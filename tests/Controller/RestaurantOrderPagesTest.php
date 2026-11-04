<?php

namespace App\Tests\Controller;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Message;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use App\Service\OrderTransitionService;
use App\Tests\Double\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The restaurant's order page: accept, extend, mark ready, reject; plus the dashboard. */
class RestaurantOrderPagesTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const RESTAURANT_EMAIL = 'restaurant-orders@foodjett.test';
    private const CUSTOMER_EMAIL = 'restaurant-orders-customer@foodjett.test';
    private const RIDER_EMAIL = 'restaurant-orders-rider@foodjett.test';

    private KernelBrowser $client;
    private string $orderId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->orderId = (string) $this->createOrder()->getId();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($entityManager->getRepository(User::class)->findOneBy(['email' => self::RESTAURANT_EMAIL]), 'main');
    }

    public function testAcceptStartsTheRiderSearchThenTimeIsAddedAndFoodMarkedReady(): void
    {
        $this->submit('Accept order', ['accept[minutes]' => '20']);
        self::assertResponseRedirects('/restaurant/orders/'.$this->orderId);

        $order = $this->order();
        self::assertSame(Order::STATUS_FINDING_RIDER, $order->getStatus());
        self::assertNotNull($order->getRiderSearchStartedAt());
        self::assertSame(20, $order->getEstimatedPrepMinutes());
        self::assertNotNull($order->getRiderPoolOffer());
        self::assertCount(3, $this->orderUpdates(), 'Each step (accepted, preparing, finding a rider) refreshes the order pages.');
        $poolUpdates = array_values(array_filter(
            self::getContainer()->get(RecordingMercureHub::class)->updates(),
            static fn ($update): bool => ['orders/pool'] === $update->getTopics(),
        ));
        self::assertCount(1, $poolUpdates);
        self::assertSame('<turbo-stream action="refresh"></turbo-stream>', $poolUpdates[0]->getData());
        self::assertFalse($poolUpdates[0]->isPrivate(), 'Pool pages refresh for every rider; each works out its own pay.');

        $conversation = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Conversation::class)
            ->findOneBy(['order' => $this->orderId, 'type' => Conversation::TYPE_CUSTOMER_RESTAURANT]);
        self::assertInstanceOf(Conversation::class, $conversation);
        $message = $conversation->getMessages()->first();
        self::assertInstanceOf(Message::class, $message);
        self::assertStringStartsWith('Thank you for ordering from Restaurant Orders Test!', $message->getBody());

        $this->submit('Add time', ['extend[minutes]' => '5']);
        self::assertCount(1, $this->orderUpdates(), 'More prep time refreshes the order pages.');

        $this->submit('Food is ready');
        self::assertCount(1, $this->orderUpdates(), 'Food ready refreshes the order pages.');
        $order = $this->order();
        self::assertSame(Order::STATUS_FINDING_RIDER, $order->getStatus(), 'The rider search keeps going.');
        self::assertNotNull($order->getReadyAt());
        self::assertSame(5, $order->getPrepExtendedMinutes());
        self::assertSame(
            ['accepted', 'preparing', 'finding_rider', 'finding_rider', 'ready'],
            array_map(static fn ($history): string => $history->getStatus(), $order->getStatusHistory()->toArray()),
        );

        $crawler = $this->client->request('GET', '/restaurant/orders/'.$this->orderId);
        self::assertCount(0, $crawler->filter('button:contains("Food is ready")'), 'Ready only once.');
        self::assertCount(0, $crawler->filter('button:contains("Accept order")'), 'Accept only once.');

        $crawler = $this->client->request('GET', '/restaurant/orders?status=finding_rider');
        self::assertCount(1, $crawler->filter('tbody tr a'));
    }

    public function testRejectionRefundsAPaidSimulatedPayment(): void
    {
        $this->submit('Reject order', ['order_reject_form[reason]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="order_reject_form"]', 'Tell the customer why.');

        $this->submit('Reject order', ['order_reject_form[reason]' => 'Kitchen equipment failure']);
        $order = $this->order();
        self::assertSame(Order::STATUS_REJECTED_BY_RESTAURANT, $order->getStatus());
        self::assertSame('Kitchen equipment failure', $order->getRejectionReason());
        $payment = $order->getPayment();
        self::assertSame(Payment::STATUS_REFUNDED, $payment->getStatus());
        self::assertSame($payment->getAmount(), $payment->getRefundedAmount());
        self::assertCount(1, $payment->getStatusHistory());
    }

    public function testAcceptingTwiceIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/restaurant/orders/'.$this->orderId);
        $form = $crawler->selectButton('Accept order')->form(['accept[minutes]' => '15']);
        $this->client->submit($form);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'cannot transition');
        self::assertCount(3, $this->order()->getStatusHistory());
    }

    public function testThePickupCodeAppearsOnceARiderIsAssigned(): void
    {
        $crawler = $this->client->request('GET', '/restaurant/orders/'.$this->orderId);
        self::assertCount(0, $crawler->filter('#rider'));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $riderUser = $this->user(self::RIDER_EMAIL, '+639187000003', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)
            ->setApprovalStatus(Rider::APPROVAL_APPROVED)->setAvailabilityStatus(Rider::AVAILABILITY_BUSY)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $entityManager->persist($riderUser);
        $entityManager->persist($rider);
        $entityManager->flush();
        $transitions = self::getContainer()->get(OrderTransitionService::class);
        $order = $transitions->transitionSequence($entityManager->find(Order::class, $this->orderId),
            [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_FINDING_RIDER], Order::ACTOR_RESTAURANT);
        $order = $transitions->transition($order, Order::STATUS_RIDER_ASSIGNED, Order::ACTOR_ADMIN,
            applyChanges: static function (Order $locked) use ($rider): void {
                $locked->setRider($rider);
            });
        $order->setPickupCode('3378');
        $entityManager->flush();

        $this->client->request('GET', '/restaurant/orders/'.$this->orderId);
        self::assertSelectorTextContains('#rider', 'Pickup code 3378');
    }

    public function testDashboardShowsTodayAndPendingAccountsSeeTheirStatus(): void
    {
        $crawler = $this->client->request('GET', '/restaurant');
        self::assertSame('1', $crawler->filter('#today dd')->first()->text(), 'Orders today');
        self::assertSelectorTextContains('main', '1 waiting for you');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getRepository(Restaurant::class)->findOneBy(['slug' => 'restaurant-orders-test'])
            ->setApprovalStatus(Restaurant::APPROVAL_REJECTED)->setRejectionReason('Blurry permit');
        $entityManager->flush();

        $this->client->request('GET', '/restaurant');
        self::assertSelectorTextContains('#approval', 'Application status: rejected');
        self::assertSelectorTextContains('#approval', 'Blurry permit');
        $this->client->request('GET', '/restaurant/orders');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string> $fields */
    private function submit(string $button, array $fields = []): Crawler
    {
        $crawler = $this->client->request('GET', '/restaurant/orders/'.$this->orderId);
        self::assertResponseIsSuccessful();

        return $this->client->submit($crawler->selectButton($button)->form($fields));
    }

    private function createOrder(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639187000001', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Restaurant Orders Test')
            ->setSlug('restaurant-orders-test')
            ->setAddress('Restaurant address')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639187000002', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Restaurant orders test')
            ->setAddressLine('Customer address')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order = (new Order())
            ->setOrderNumber('REST-ORDER-'.bin2hex(random_bytes(4)))
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setCustomerAddress($address)
            ->setStatus(Order::STATUS_PLACED)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod(Payment::METHOD_CARD)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod(Payment::METHOD_CARD)
            ->setStatus(Payment::STATUS_PAID)
            ->setAmount('135.00')
            ->setTransactionReference('SIMULATED-TEST')
            ->setPaidAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setPayment($payment);

        foreach ([$restaurantUser, $restaurant, $customerUser, $customer, $address, $order, $payment] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return $order;
    }

    private function user(string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Restaurant Order Test')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)
            ->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function order(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $this->orderId);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function orderUpdates(): array
    {
        return array_values(array_filter(
            self::getContainer()->get(RecordingMercureHub::class)->updates(),
            fn ($update): bool => ['orders/'.$this->orderId.'/status'] === $update->getTopics(),
        ));
    }
}
