<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderPoolOffer;
use App\Entity\User;
use App\Repository\DeliveryZoneRepository;
use App\Tests\Double\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Admin order pages: the list, one order in full, cancelling with a refund and assigning a rider. */
class AdminOrderPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $paidOrderId;
    private string $poolOrderId;
    private string $riderId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(RecordingMercureHub::class)->reset();
        $this->createFixture();
    }

    public function testAdminCancelsAnOrderAndRefundsThePayment(): void
    {
        $url = '/admin/order/'.$this->paidOrderId;
        $this->client->submit($this->page($url)->selectButton('Cancel order')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="cancel"]', 'Give a reason.');
        self::assertSame(Order::STATUS_RIDER_ASSIGNED, $this->order($this->paidOrderId)->getStatus());

        $this->client->submit($this->page($url)->selectButton('Cancel order')->form(['cancel[reason]' => 'Manual fraud review cancellation']));
        self::assertResponseRedirects($url);

        $order = $this->order($this->paidOrderId);
        self::assertSame(Order::STATUS_CANCELLED_BY_ADMIN, $order->getStatus());
        self::assertSame(Order::ACTOR_ADMIN, $order->getCancelledBy());
        self::assertSame('Manual fraud review cancellation', $order->getCancellationReason());
        self::assertSame(Payment::STATUS_REFUNDED, $order->getPayment()?->getStatus());
        self::assertSame($order->getPayment()?->getAmount(), $order->getPayment()?->getRefundedAmount());
        self::assertCount(1, $order->getStatusHistory());
        self::assertCount(1, $order->getPayment()?->getStatusHistory());
        self::assertContains('order.cancelled', $this->auditActions($this->paidOrderId));

        $crawler = $this->page($url);
        self::assertSelectorTextContains('#cancellation', 'Manual fraud review cancellation');
        self::assertCount(0, $crawler->selectButton('Cancel order'));
    }

    public function testListFiltersByStatusAndDetailShowsEverything(): void
    {
        $crawler = $this->page('/admin/order?status=rider_assigned');
        self::assertSelectorTextContains('#order-'.$this->paidOrderId, 'Admin Cancel Test Restaurant');
        self::assertCount(0, $crawler->filter('#order-'.$this->poolOrderId));

        $this->page('/admin/order/'.$this->paidOrderId);
        self::assertSelectorTextContains('#payment', 'paid');
        self::assertSelectorTextContains('#address', 'Test address');
        self::assertSelectorTextContains('#money', '₱135.00');
        self::assertSelectorExists('#timeline');
    }

    public function testQueueListsEscalatedOrders(): void
    {
        $this->page('/admin/order/unassigned');
        self::assertSelectorTextContains('#order-'.$this->poolOrderId, 'Admin Pool Restaurant');
        self::assertSelectorTextContains('#order-'.$this->poolOrderId, 'admin alerted');
        $minutes = (int) $this->client->getCrawler()->filter('#order-'.$this->poolOrderId.' .minutes')->text();
        self::assertGreaterThanOrEqual(6, $minutes);
        self::assertSelectorNotExists('#order-'.$this->paidOrderId);
    }

    public function testAdminAssignsAnApprovedOfflineRider(): void
    {
        $url = '/admin/order/'.$this->poolOrderId;
        $this->client->submit($this->page($url)->selectButton('Assign rider')->form(['admin_assign_rider_form[rider]' => $this->riderId]));
        self::assertResponseRedirects($url);

        $order = $this->order($this->poolOrderId);
        self::assertSame(Order::STATUS_RIDER_ASSIGNED, $order->getStatus());
        self::assertSame($this->riderId, (string) $order->getRider()?->getId());
        self::assertTrue($order->getRiderPoolOffer()?->isAdminAssigned());
        self::assertSame(
            number_format(round(DeliveryZoneRepository::distanceKm(14.5996000, 120.9843000, 14.5995000, 120.9842000), 4), 4, '.', ''),
            $order->getRiderPoolOffer()?->getAcceptedPickupDistanceKm(),
        );
        self::assertSame(Rider::AVAILABILITY_BUSY, $order->getRider()?->getAvailabilityStatus());
        self::assertNotNull($order->getRiderAssignedAt());
        self::assertMatchesRegularExpression('/^\d{4}$/', (string) $order->getPickupCode());
        self::assertContains('order.rider_assigned', $this->auditActions($this->poolOrderId));

        $poolUpdates = array_values(array_filter(
            self::getContainer()->get(RecordingMercureHub::class)->updates(),
            static fn ($update): bool => ['orders/pool'] === $update->getTopics(),
        ));
        self::assertCount(1, $poolUpdates);
        self::assertSame('<turbo-stream action="refresh"></turbo-stream>', $poolUpdates[0]->getData(), 'The assigned order leaves every pool page.');

        $this->page($url);
        self::assertSelectorTextContains('#rider', 'assigned by an admin');
        self::assertSelectorNotExists('form[name="admin_assign_rider_form"]');
    }

    public function testNonAdminsAreRefused(): void
    {
        $this->client->loginUser($this->order($this->paidOrderId)->getCustomer()->getUser(), 'main');
        $this->client->request('GET', '/admin/order/'.$this->paidOrderId);
        self::assertResponseStatusCodeSame(403);
    }

    private function createFixture(): void
    {
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Order Admin', 'order-admin@foodjett.test', '+639186300001', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Order Customer', 'order-admin-customer@foodjett.test', '+639186300002', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Admin Cancel Test Address')->setAddressLine('Test address')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Order Owner', 'order-admin-restaurant@foodjett.test', '+639186300003', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = $this->restaurant($restaurantUser, 'Admin Cancel Test Restaurant', 'admin-cancel-test-restaurant', $now);
        $paidOrder = $this->newOrder('ADMIN-CANCEL-ORDER', $customer, $address, $restaurant, Order::STATUS_RIDER_ASSIGNED, Payment::METHOD_GCASH, $now);
        $payment = (new Payment())->setOrder($paidOrder)->setMethod(Payment::METHOD_GCASH)->setStatus(Payment::STATUS_PAID)->setAmount('135.00')
            ->setPaidAt($now)->setTransactionReference('SIMULATED-ADMIN-CANCEL')->setCreatedAt($now)->setUpdatedAt($now);
        $paidOrder->setPayment($payment);

        $riderUser = $this->user('Pool Rider', 'admin-pool-rider@foodjett.test', '+639186500002', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setPlateNumber('ADMIN-POOL')
            ->setApprovalStatus(Rider::APPROVAL_APPROVED)->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)
            ->setCurrentLatitude('14.5996000')->setCurrentLongitude('120.9843000')->setLastLocationAt($now)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $poolRestaurantUser = $this->user('Pool Owner', 'admin-pool-restaurant@foodjett.test', '+639186500003', User::ACCOUNT_RESTAURANT, $now);
        $poolRestaurant = $this->restaurant($poolRestaurantUser, 'Admin Pool Restaurant', 'admin-pool-restaurant', $now);
        $poolOrder = $this->newOrder('ADMIN-POOL-ORDER', $customer, $address, $poolRestaurant, Order::STATUS_FINDING_RIDER, Payment::METHOD_CARD, $now)
            ->setRiderSearchStartedAt($now->modify('-7 minutes'))->setPlacedAt($now->modify('-8 minutes'));
        $offer = (new RiderPoolOffer())->setOrder($poolOrder)->setSearchRadiusKm('8.0')->setIncentiveAmount('20.00')
            ->setEscalationStage(RiderPoolOffer::STAGE_ADMIN_ALERTED)->setCreatedAt($now)->setUpdatedAt($now);
        $poolOrder->setRiderPoolOffer($offer);

        foreach ([$adminUser, $admin, $customerUser, $customer, $address, $restaurantUser, $restaurant, $paidOrder, $payment,
            $riderUser, $rider, $poolRestaurantUser, $poolRestaurant, $poolOrder, $offer] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->paidOrderId = (string) $paidOrder->getId();
        $this->poolOrderId = (string) $poolOrder->getId();
        $this->riderId = (string) $rider->getId();
        $this->client->loginUser($adminUser, 'main');
    }

    private function restaurant(User $user, string $name, string $slug, \DateTimeImmutable $now): Restaurant
    {
        return (new Restaurant())->setUser($user)->setName($name)->setSlug($slug)->setAddress('Test restaurant')
            ->setLatitude('14.5995000')->setLongitude('120.9842000')->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setOperatingStatus(Restaurant::OPERATING_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function newOrder(string $number, Customer $customer, CustomerAddress $address, Restaurant $restaurant, string $status, string $method, \DateTimeImmutable $now): Order
    {
        return (new Order())->setOrderNumber($number)->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)
            ->setStatus($status)->setSubtotal('100.00')->setDeliveryFee('35.00')->setTotalAmount('135.00')
            ->setPaymentMethod($method)->setPlacedAt($now)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function user(string $name, string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)
            ->setPassword(password_hash('TestPassword123!', PASSWORD_BCRYPT, ['cost' => 4]))->setRole($role)->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function page(string $url): Crawler
    {
        $this->entityManager()->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function order(string $id): Order
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(Order::class, $id) ?? self::fail('Order '.$id.' is missing.');
    }

    /** @return list<string> */
    private function auditActions(string $subjectId): array
    {
        return $this->entityManager()->getRepository(AuditLog::class)->createQueryBuilder('log')->select('log.action')
            ->andWhere('log.subjectId = :id')->setParameter('id', $subjectId)->getQuery()->getSingleColumnResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
