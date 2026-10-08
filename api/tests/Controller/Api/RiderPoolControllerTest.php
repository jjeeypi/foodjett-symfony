<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderPoolOffer;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\RiderPoolEscalationStage;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RiderPoolControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const RIDER_EMAIL = 'pool-rider@foodjett.test';
    private const RESTAURANT_EMAIL = 'pool-restaurant@foodjett.test';
    private const CUSTOMER_EMAIL = 'pool-customer@foodjett.test';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testPoolReturnsOnlyVisibleOrdersAndExcludesCodAtCashLimit(): void
    {
        $this->client->request('GET', '/api/rider/pool', server: $this->auth($this->login()));

        self::assertResponseIsSuccessful();
        $orders = $this->payload()['orders'] ?? [];
        $testOrders = array_values(array_filter($orders, static fn (array $order): bool => str_starts_with($order['order_number'], 'POOL-')));
        self::assertCount(1, $testOrders);
        self::assertSame('POOL-CARD', $testOrders[0]['order_number']);
        self::assertSame('Pool Test Restaurant', $testOrders[0]['restaurant']['name']);
        self::assertSame('initial', $testOrders[0]['escalation_stage']);
        self::assertSame('card', $testOrders[0]['payment_method']);
        self::assertArrayHasKey('pickup_distance_km', $testOrders[0]);
        self::assertArrayHasKey('delivery_distance_km', $testOrders[0]);
        self::assertArrayHasKey('total', $testOrders[0]['estimated_pay']);
    }

    public function testOfflineRiderGetsAnEmptyPoolWithReason(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $rider = $entityManager->getRepository(Rider::class)->findOneBy(['user' => $entityManager->getRepository(User::class)->findOneBy(['email' => self::RIDER_EMAIL])]);
        self::assertInstanceOf(Rider::class, $rider);
        $rider->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE);
        $entityManager->flush();

        $this->client->request('GET', '/api/rider/pool', server: $this->auth($this->login()));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['orders'] ?? null);
        self::assertSame('Go online to view available orders.', $this->payload()['reason'] ?? null);
    }

    private function createFixture(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $riderUser = $this->user(self::RIDER_EMAIL, '+639186400001', UserRole::RIDER, $now);
        $rider = (new Rider())
            ->setUser($riderUser)
            ->setVehicleType(VehicleType::MOTORCYCLE)
            ->setPlateNumber('POOL-001')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setAvailabilityStatus(RiderAvailabilityStatus::AVAILABLE)
            ->setCurrentLatitude('14.5996000')
            ->setCurrentLongitude('120.9843000')
            ->setLastLocationAt($now)
            ->setCashOnHand('2000.00')
            ->setCashRemitLimit('2000.00')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186400002', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Pool Test Restaurant')
            ->setSlug('pool-test-restaurant')
            ->setAddress('Pool test pickup')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186400003', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Pool Test Address')
            ->setAddressLine('Pool test delivery')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        foreach ([$riderUser, $rider, $restaurantUser, $restaurant, $customerUser, $customer, $address] as $entity) {
            $entityManager->persist($entity);
        }
        $this->order($entityManager, $customer, $restaurant, $address, 'POOL-CARD', PaymentMethod::CARD, $now);
        $this->order($entityManager, $customer, $restaurant, $address, 'POOL-COD', PaymentMethod::COD, $now);
        $entityManager->flush();
    }

    private function order(
        EntityManagerInterface $entityManager,
        Customer $customer,
        Restaurant $restaurant,
        CustomerAddress $address,
        string $number,
        PaymentMethod $paymentMethod,
        \DateTimeImmutable $now,
    ): void {
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setCustomerAddress($address)
            ->setStatus(OrderStatus::FINDING_RIDER)
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
            ->setEscalationStage(RiderPoolEscalationStage::INITIAL)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setRiderPoolOffer($offer);
        $entityManager->persist($order);
        $entityManager->persist($offer);
    }

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Pool Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function login(): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::RIDER_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        return $this->payload()['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ([
            'DELETE FROM rider_pool_declines WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM rider_pool_offers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM orders WHERE order_number LIKE ?',
        ] as $sql) {
            $connection->executeStatement($sql, ['POOL-%']);
        }
        $connection->executeStatement('DELETE FROM customer_addresses WHERE label = ?', ['Pool Test Address']);
        $connection->executeStatement('DELETE FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RIDER_EMAIL]);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?)', [self::RIDER_EMAIL, self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
