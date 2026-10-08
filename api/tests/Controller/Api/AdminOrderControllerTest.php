<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminOrderControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'order-admin@foodjett.test';
    private const CUSTOMER_EMAIL = 'order-admin-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'order-admin-restaurant@foodjett.test';

    private KernelBrowser $client;
    private string $orderId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        $this->orderId = (string) $this->createFixture()->getId();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAdminCancelsAnyNonTerminalOrderAndRefundsPayment(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/orders/'.$this->orderId.'/cancel', [
            'reason' => 'Manual fraud review cancellation',
        ], server: $this->auth($token));

        self::assertResponseIsSuccessful();
        $payload = $this->payload()['order'] ?? [];
        self::assertSame(OrderStatus::CANCELLED_BY_ADMIN->value, $payload['status'] ?? null);
        self::assertSame(OrderActor::ADMIN->value, $payload['cancelled_by'] ?? null);
        self::assertSame(PaymentStatus::REFUNDED->value, $payload['payment_status'] ?? null);

        $order = $this->reload();
        self::assertSame('Manual fraud review cancellation', $order->getCancellationReason());
        self::assertSame($order->getPayment()?->getAmount(), $order->getPayment()?->getRefundedAmount());
        self::assertCount(1, $order->getStatusHistory());
        self::assertCount(1, $order->getPayment()?->getStatusHistory());

        $this->client->jsonRequest('POST', '/api/admin/orders/'.$this->orderId.'/cancel', [
            'reason' => 'Repeat',
        ], server: $this->auth($token));
        self::assertResponseStatusCodeSame(409);
    }

    public function testAdminCancellationRequiresReason(): void
    {
        $this->client->jsonRequest('POST', '/api/admin/orders/'.$this->orderId.'/cancel', [], server: $this->auth($this->login()));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrderStatus::RIDER_ASSIGNED, $this->reload()->getStatus());
    }

    private function createFixture(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $adminUser = $this->user(self::ADMIN_EMAIL, '+639186300001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186300002', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Admin Cancel Test Address')
            ->setAddressLine('Test address')
            ->setLatitude('14.6000000')
            ->setLongitude('120.9850000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186300003', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Admin Cancel Test Restaurant')
            ->setSlug('admin-cancel-test-restaurant')
            ->setAddress('Test restaurant')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order = (new Order())
            ->setOrderNumber('ADMIN-CANCEL-'.bin2hex(random_bytes(5)))
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setCustomerAddress($address)
            ->setStatus(OrderStatus::RIDER_ASSIGNED)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod(PaymentMethod::GCASH)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod(PaymentMethod::GCASH)
            ->setStatus(PaymentStatus::PAID)
            ->setAmount('135.00')
            ->setPaidAt($now)
            ->setTransactionReference('SIMULATED-ADMIN-CANCEL')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setPayment($payment);
        foreach ([$adminUser, $admin, $customerUser, $customer, $address, $restaurantUser, $restaurant, $order, $payment] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return $order;
    }

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Admin Order Test')
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

    private function reload(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $this->orderId);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ([
            'DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?))',
            'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM orders WHERE order_number LIKE ?',
        ] as $sql) {
            $connection->executeStatement($sql, ['ADMIN-CANCEL-%']);
        }
        $connection->executeStatement('DELETE FROM customer_addresses WHERE label = ?', ['Admin Cancel Test Address']);
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::ADMIN_EMAIL]);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?)', [self::ADMIN_EMAIL, self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
