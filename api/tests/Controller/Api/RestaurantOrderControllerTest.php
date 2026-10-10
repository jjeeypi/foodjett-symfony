<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Tests\Double\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RestaurantOrderControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const RESTAURANT_EMAIL = 'restaurant-orders@foodjett.test';
    private const CUSTOMER_EMAIL = 'restaurant-orders-customer@foodjett.test';

    private KernelBrowser $client;
    private string $orderId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(RecordingMercureHub::class)->reset();
        $this->cleanup();
        $this->orderId = (string) $this->createOrder()->getId();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testRestaurantAcceptsExtendsAndMarksOrderReadyWhileSearchContinues(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderId.'/accept', [
            'estimated_prep_minutes' => 20,
        ], server: $this->auth($token));

        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame(OrderStatus::FINDING_RIDER->value, $payload['order']['status'] ?? null);
        self::assertNotNull($payload['order']['rider_search_started_at'] ?? null);
        $acceptUpdates = $this->orderUpdates();
        self::assertCount(3, $acceptUpdates);
        self::assertSame(
            ['accepted', 'preparing', 'finding_rider'],
            array_map(static fn ($update): string => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['status'], $acceptUpdates),
        );

        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderId.'/extend-prep-time', [
            'minutes' => 5,
        ], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $extensionUpdates = $this->orderUpdates();
        self::assertCount(1, $extensionUpdates);
        $extensionPayload = json_decode($extensionUpdates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('prep_time_extended', $extensionPayload['kind'] ?? null);
        self::assertNotNull($extensionPayload['estimated_ready_at'] ?? null);

        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderId.'/mark-ready', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $readyPayload = $this->payload();
        self::assertSame(OrderStatus::FINDING_RIDER->value, $readyPayload['order']['status'] ?? null);
        self::assertNotNull($readyPayload['order']['ready_at'] ?? null);
        $readyUpdates = $this->orderUpdates();
        self::assertCount(1, $readyUpdates);
        self::assertSame('food_ready', json_decode($readyUpdates[0]->getData(), true, flags: JSON_THROW_ON_ERROR)['kind'] ?? null);

        $this->client->request('GET', '/api/restaurant/orders?status=finding_rider', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['orders'] ?? []);

        $order = $this->order();
        self::assertNotNull($order->getRiderPoolOffer());
        self::assertSame(5, $order->getPrepExtendedMinutes());
        self::assertSame(
            ['accepted', 'preparing', 'finding_rider', 'finding_rider', 'ready'],
            array_map(static fn ($history): string => $history->getStatus(), $order->getStatusHistory()->toArray()),
        );

    }

    public function testRestaurantRejectionRefundsPaidSimulatedPayment(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderId.'/reject', [
            'reason' => 'Kitchen equipment failure',
        ], server: $this->auth($token));

        self::assertResponseIsSuccessful();
        self::assertSame(OrderStatus::REJECTED_BY_RESTAURANT->value, $this->payload()['order']['status'] ?? null);

        $payment = $this->order()->getPayment();
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::REFUNDED, $payment->getStatus());
        self::assertSame($payment->getAmount(), $payment->getRefundedAmount());
        self::assertNotNull($payment->getRefundedAt());
        self::assertCount(1, $payment->getStatusHistory());
    }

    public function testRestaurantCannotAcceptOrderTwice(): void
    {
        $token = $this->login();
        $body = ['estimated_prep_minutes' => 15];
        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderId.'/accept', $body, server: $this->auth($token));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderId.'/accept', $body, server: $this->auth($token));
        self::assertResponseStatusCodeSame(409);
    }

    private function createOrder(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639187000001', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Restaurant Orders Test')
            ->setSlug('restaurant-orders-test')
            ->setAddress('Restaurant address')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639187000002', UserRole::CUSTOMER, $now);
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
            ->setStatus(OrderStatus::PLACED)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod(PaymentMethod::CARD)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod(PaymentMethod::CARD)
            ->setStatus(PaymentStatus::PAID)
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

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Restaurant Order Test')
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
        $this->client->jsonRequest('POST', '/api/login', [
            'login' => self::RESTAURANT_EMAIL,
            'password' => self::PASSWORD,
        ]);
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

    private function order(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $this->orderId);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    /** @return list<\Symfony\Component\Mercure\Update> */
    private function orderUpdates(): array
    {
        return array_values(array_filter(
            self::getContainer()->get(RecordingMercureHub::class)->updates(),
            fn ($update): bool => ['orders/'.$this->orderId.'/status'] === $update->getTopics(),
        ));
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $pattern = 'REST-ORDER-%';
        foreach ([
            'DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?))',
            'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM rider_pool_offers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM orders WHERE order_number LIKE ?',
        ] as $sql) {
            $connection->executeStatement($sql, [$pattern]);
        }
        $connection->executeStatement("DELETE FROM customer_addresses WHERE label = 'Restaurant orders test'");
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))', [self::RESTAURANT_EMAIL, self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))', [self::RESTAURANT_EMAIL, self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?)', [self::RESTAURANT_EMAIL, self::CUSTOMER_EMAIL]);
    }
}
