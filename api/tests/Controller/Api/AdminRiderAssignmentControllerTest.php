<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
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

final class AdminRiderAssignmentControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'admin-pool@foodjett.test';
    private const RIDER_EMAIL = 'admin-pool-rider@foodjett.test';
    private const RESTAURANT_EMAIL = 'admin-pool-restaurant@foodjett.test';
    private const CUSTOMER_EMAIL = 'admin-pool-customer@foodjett.test';

    private KernelBrowser $client;
    private string $orderId;
    private string $riderId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        [$this->orderId, $this->riderId] = $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAdminQueueListsAlertedUnassignedOrders(): void
    {
        $this->client->request('GET', '/api/admin/orders/unassigned', server: $this->auth($this->login()));

        self::assertResponseIsSuccessful();
        $orders = array_values(array_filter(
            $this->payload()['orders'] ?? [],
            static fn (array $order): bool => 'ADMIN-POOL-ORDER' === $order['order_number'],
        ));
        self::assertCount(1, $orders);
        self::assertSame('admin_alerted', $orders[0]['escalation_stage']);
        self::assertSame('Admin Pool Restaurant', $orders[0]['restaurant_name']);
        self::assertGreaterThanOrEqual(6, $orders[0]['searching_minutes']);
    }

    public function testAdminCanAssignApprovedOfflineRider(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/orders/'.$this->orderId.'/assign-rider', [
            'rider_id' => $this->riderId,
        ], server: $this->auth($this->login()));

        self::assertResponseIsSuccessful();
        $payload = $this->payload()['order'] ?? [];
        self::assertSame(OrderStatus::RIDER_ASSIGNED->value, $payload['status'] ?? null);
        self::assertSame($this->riderId, $payload['rider_id'] ?? null);
        self::assertTrue($payload['admin_assigned'] ?? false);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $this->orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertTrue($order->getRiderPoolOffer()?->isAdminAssigned());
        self::assertSame(RiderAvailabilityStatus::BUSY, $order->getRider()?->getAvailabilityStatus());
        self::assertNotNull($order->getRiderAssignedAt());
        self::assertMatchesRegularExpression('/^\d{4}$/', (string) $order->getPickupCode());
    }

    /** @return array{string, string} */
    private function createFixture(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $adminUser = $this->user(self::ADMIN_EMAIL, '+639186500001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $riderUser = $this->user(self::RIDER_EMAIL, '+639186500002', UserRole::RIDER, $now);
        $rider = (new Rider())
            ->setUser($riderUser)
            ->setVehicleType(VehicleType::MOTORCYCLE)
            ->setPlateNumber('ADMIN-POOL')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE)
            ->setCurrentLatitude('14.5996000')
            ->setCurrentLongitude('120.9843000')
            ->setLastLocationAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186500003', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Admin Pool Restaurant')
            ->setSlug('admin-pool-restaurant')
            ->setAddress('Admin pool pickup')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186500004', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Admin Pool Address')
            ->setAddressLine('Admin pool delivery')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order = (new Order())
            ->setOrderNumber('ADMIN-POOL-ORDER')
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setCustomerAddress($address)
            ->setStatus(OrderStatus::FINDING_RIDER)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod(PaymentMethod::CARD)
            ->setRiderSearchStartedAt($now->modify('-7 minutes'))
            ->setPlacedAt($now->modify('-8 minutes'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $offer = (new RiderPoolOffer())
            ->setOrder($order)
            ->setSearchRadiusKm('8.0')
            ->setIncentiveAmount('20.00')
            ->setEscalationStage(RiderPoolEscalationStage::ADMIN_ALERTED)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setRiderPoolOffer($offer);
        foreach ([$adminUser, $admin, $riderUser, $rider, $restaurantUser, $restaurant, $customerUser, $customer, $address, $order, $offer] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [(string) $order->getId(), (string) $rider->getId()];
    }

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Admin Pool Test User')
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
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]);
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
            'DELETE FROM rider_pool_declines WHERE order_id IN (SELECT id FROM orders WHERE order_number = ?)',
            'DELETE FROM rider_pool_offers WHERE order_id IN (SELECT id FROM orders WHERE order_number = ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number = ?)',
            'DELETE FROM orders WHERE order_number = ?',
        ] as $sql) {
            $connection->executeStatement($sql, ['ADMIN-POOL-ORDER']);
        }
        $connection->executeStatement('DELETE FROM customer_addresses WHERE label = ?', ['Admin Pool Address']);
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::ADMIN_EMAIL]);
        $connection->executeStatement('DELETE FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RIDER_EMAIL]);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?, ?)', [self::ADMIN_EMAIL, self::RIDER_EMAIL, self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
