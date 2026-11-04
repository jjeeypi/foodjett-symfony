<?php

namespace App\Tests\Controller;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderPoolOffer;
use App\Entity\User;
use App\Repository\DeliveryZoneRepository;
use App\Service\OrderTransitionService;
use App\Tests\Double\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The rider pool page: visibility, reasons, first-come acceptance and declining. */
class RiderPoolPageTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const RIDER_EMAIL = 'pool-rider@foodjett.test';
    private const SECOND_RIDER_EMAIL = 'pool-rider-two@foodjett.test';
    private const RESTAURANT_EMAIL = 'pool-restaurant@foodjett.test';
    private const CUSTOMER_EMAIL = 'pool-customer@foodjett.test';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(RecordingMercureHub::class)->reset();
        $this->createFixture();
    }

    public function testPoolShowsOnlyVisibleOrdersAndHidesCodAtTheCashLimit(): void
    {
        $crawler = $this->pool(self::RIDER_EMAIL);
        $card = $this->orderByNumber('POOL-CARD');
        self::assertCount(1, $crawler->filter('article'));
        self::assertCount(1, $crawler->filter('#offer-'.$card->getId()));
        self::assertSelectorTextContains('#offer-'.$card->getId(), 'Pool Test Restaurant');
        self::assertSelectorTextContains('#offer-'.$card->getId(), 'paid online');
        self::assertMatchesRegularExpression('/Earn about PHP \d+\.\d{2}/', $crawler->filter('#offer-'.$card->getId())->text());
    }

    public function testOfflineRiderIsToldToGoOnline(): void
    {
        $this->rider(self::RIDER_EMAIL)->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE);
        $this->entityManager()->flush();

        $crawler = $this->pool(self::RIDER_EMAIL);
        self::assertCount(0, $crawler->filter('article'));
        self::assertSelectorTextContains('#pool-reason', 'Go online to see available orders.');
    }

    public function testPoolExplainsWhenOffersAreOutsideTheRidersRadius(): void
    {
        $this->rider(self::RIDER_EMAIL)->setCurrentLatitude('9.3154120')->setCurrentLongitude('123.3025280');
        $this->entityManager()->flush();

        $this->pool(self::RIDER_EMAIL);
        self::assertSelectorTextContains('#pool-reason', 'outside its current');
        self::assertSelectorTextContains('#pool-reason', 'restaurant map pin');
    }

    public function testRiderAcceptsAtomicallyAndAStaleCompetitorLosesTheRace(): void
    {
        $order = $this->orderByNumber('POOL-CARD');
        $staleSnapshot = clone $order;

        $this->client->submit($this->offerForm($this->pool(self::RIDER_EMAIL), $order, 'Accept'));
        self::assertResponseRedirects('/rider/delivery');

        $accepted = $this->reloadOrder((string) $order->getId());
        self::assertSame(Order::STATUS_RIDER_ASSIGNED, $accepted->getStatus());
        self::assertMatchesRegularExpression('/^\d{4}$/', (string) $accepted->getPickupCode());
        self::assertNotNull($this->entityManager()->getRepository(Conversation::class)->findOneBy(['order' => $order->getId(), 'type' => Conversation::TYPE_CUSTOMER_RIDER]));
        $poolUpdates = array_values(array_filter(
            self::getContainer()->get(RecordingMercureHub::class)->updates(),
            static fn ($update): bool => ['orders/pool'] === $update->getTopics(),
        ));
        self::assertCount(1, $poolUpdates);
        self::assertSame('rider_pool.offer_taken', $poolUpdates[0]->getType());
        self::assertSame('rider_assigned', json_decode($poolUpdates[0]->getData(), true)['reason']);

        $this->entityManager()->clear();
        $second = $this->rider(self::SECOND_RIDER_EMAIL);
        try {
            self::getContainer()->get(OrderTransitionService::class)->acceptPoolOrder($staleSnapshot, $second);
            self::fail('The stale competing acceptance should fail after the database lock is acquired.');
        } catch (ConflictHttpException $exception) {
            self::assertSame('This order was just taken by another rider.', $exception->getMessage());
        }

        $won = $this->reloadOrder((string) $order->getId());
        self::assertSame(self::RIDER_EMAIL, $won->getRider()?->getUser()->getEmail());
        self::assertSame(Rider::AVAILABILITY_BUSY, $won->getRider()?->getAvailabilityStatus());
        self::assertSame(
            number_format(round(DeliveryZoneRepository::distanceKm(14.5996000, 120.9843000, 14.5995000, 120.9842000), 4), 4, '.', ''),
            $won->getRiderPoolOffer()?->getAcceptedPickupDistanceKm(),
        );
    }

    public function testAnOrderTakenMeanwhileGivesTheOtherRiderAClearMessage(): void
    {
        $order = $this->orderByNumber('POOL-CARD');
        $form = $this->offerForm($this->pool(self::SECOND_RIDER_EMAIL), $order, 'Accept');
        self::getContainer()->get(OrderTransitionService::class)->acceptPoolOrder($order, $this->rider(self::RIDER_EMAIL));

        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'This order was just taken by another rider.');
    }

    public function testDeclineIsIdempotentAndHidesTheOrder(): void
    {
        $order = $this->orderByNumber('POOL-CARD');
        $form = $this->offerForm($this->pool(self::RIDER_EMAIL), $order, 'Decline');
        $this->client->submit($form);
        $this->client->submit($form);

        $crawler = $this->pool(self::RIDER_EMAIL);
        self::assertCount(0, $crawler->filter('#offer-'.$order->getId()));
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM rider_pool_declines WHERE order_id = ?', [$order->getId()],
        ));
    }

    public function testAcceptRechecksTheCashLimitAtTheLastMoment(): void
    {
        $cod = $this->orderByNumber('POOL-COD');
        $this->rider(self::RIDER_EMAIL)->setCashOnHand('0.00');
        $this->entityManager()->flush();
        $form = $this->offerForm($this->pool(self::RIDER_EMAIL), $cod, 'Accept');
        $this->rider(self::RIDER_EMAIL)->setCashOnHand('2000.00');
        $this->entityManager()->flush();

        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Remit cash before accepting another COD order.');
        self::assertSame(Order::STATUS_FINDING_RIDER, $this->reloadOrder((string) $cod->getId())->getStatus());
    }

    public function testARiderCarriesOneOrderAtATime(): void
    {
        $card = $this->orderByNumber('POOL-CARD');
        $cod = $this->orderByNumber('POOL-COD');
        $this->rider(self::RIDER_EMAIL)->setCashOnHand('0.00');
        $this->entityManager()->flush();
        $crawler = $this->pool(self::RIDER_EMAIL);
        $codForm = $this->offerForm($crawler, $cod, 'Accept');

        $this->client->submit($this->offerForm($crawler, $card, 'Accept'));
        self::assertResponseRedirects('/rider/delivery');
        $this->client->submit($codForm);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Finish your current delivery before accepting another order.');
        self::assertSame(Order::STATUS_FINDING_RIDER, $this->reloadOrder((string) $cod->getId())->getStatus());
    }

    private function pool(string $email): Crawler
    {
        $this->entityManager()->clear();
        $this->client->loginUser($this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]), 'main');
        $crawler = $this->client->request('GET', '/rider/pool');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function offerForm(Crawler $crawler, Order $order, string $button): Form
    {
        return $crawler->filter('#offer-'.$order->getId())->selectButton($button)->form();
    }

    private function orderByNumber(string $number): Order
    {
        return $this->entityManager()->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
    }

    private function rider(string $email): Rider
    {
        return $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email])->getRider();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createFixture(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $riderUser = $this->user(self::RIDER_EMAIL, '+639186400001', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())
            ->setUser($riderUser)
            ->setVehicleType(Rider::VEHICLE_MOTORCYCLE)
            ->setPlateNumber('POOL-001')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE)
            ->setCurrentLatitude('14.5996000')
            ->setCurrentLongitude('120.9843000')
            ->setLastLocationAt($now)
            ->setCashOnHand('2000.00')
            ->setCashRemitLimit('2000.00')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $secondRiderUser = $this->user(self::SECOND_RIDER_EMAIL, '+639186400004', User::ACCOUNT_RIDER, $now);
        $secondRider = (new Rider())
            ->setUser($secondRiderUser)
            ->setVehicleType(Rider::VEHICLE_MOTORCYCLE)
            ->setPlateNumber('POOL-002')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE)
            ->setCurrentLatitude('14.5997000')
            ->setCurrentLongitude('120.9844000')
            ->setLastLocationAt($now)
            ->setCashOnHand('0.00')
            ->setCashRemitLimit('2000.00')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186400002', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Pool Test Restaurant')
            ->setSlug('pool-test-restaurant')
            ->setAddress('Pool test pickup')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186400003', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Pool Test Address')
            ->setAddressLine('Pool test delivery')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        foreach ([$riderUser, $rider, $secondRiderUser, $secondRider, $restaurantUser, $restaurant, $customerUser, $customer, $address] as $entity) {
            $entityManager->persist($entity);
        }
        $this->order($entityManager, $customer, $restaurant, $address, 'POOL-CARD', Payment::METHOD_CARD, $now);
        $this->order($entityManager, $customer, $restaurant, $address, 'POOL-COD', Payment::METHOD_COD, $now);
        $entityManager->flush();
    }

    private function order(
        EntityManagerInterface $entityManager,
        Customer $customer,
        Restaurant $restaurant,
        CustomerAddress $address,
        string $number,
        string $paymentMethod,
        \DateTimeImmutable $now,
    ): void {
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setCustomerAddress($address)
            ->setStatus(Order::STATUS_FINDING_RIDER)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod($paymentMethod)
            ->setEstimatedReadyAt($now->modify('+15 minutes'))
            ->setRiderSearchStartedAt($now)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $offer = (new RiderPoolOffer())
            ->setOrder($order)
            ->setSearchRadiusKm('3.0')
            ->setIncentiveAmount('20.00')
            ->setEscalationStage(RiderPoolOffer::STAGE_INITIAL)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setRiderPoolOffer($offer);
        $entityManager->persist($order);
        $entityManager->persist($offer);
    }

    private function user(string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Pool Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)
            ->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function reloadOrder(string $id): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $id);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }
}
