<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\RiderPoolOffer;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderPoolEscalationStage;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerOrderCancellationControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const CUSTOMER_EMAIL = 'cancel-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'cancel-restaurant@foodjett.test';

    private KernelBrowser $client;
    private string $customerId;
    private string $restaurantId;
    private string $addressId;

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

    public function testCustomerCancelsPlacedPaidOrderAndReceivesFullRefund(): void
    {
        $order = $this->createOrder(OrderStatus::PLACED, PaymentMethod::CARD, PaymentStatus::PAID);

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$order->getId().'/cancel', [
            'reason' => 'Changed my mind',
        ], server: $this->auth($this->login()));

        self::assertResponseIsSuccessful();
        self::assertSame(OrderStatus::CANCELLED_BY_CUSTOMER->value, $this->payload()['order']['status'] ?? null);
        $cancelled = $this->reload((string) $order->getId());
        self::assertSame(PaymentStatus::REFUNDED, $cancelled->getPayment()?->getStatus());
        self::assertSame($cancelled->getPayment()?->getAmount(), $cancelled->getPayment()?->getRefundedAmount());
        self::assertCount(1, $cancelled->getPayment()?->getStatusHistory());
    }

    public function testFindingRiderCancellationRequiresCustomerNotifiedEscalation(): void
    {
        $order = $this->createOrder(OrderStatus::FINDING_RIDER, PaymentMethod::COD, PaymentStatus::PENDING, RiderPoolEscalationStage::INITIAL);
        $token = $this->login();

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$order->getId().'/cancel', [], server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $offer = $entityManager->find(RiderPoolOffer::class, $order->getRiderPoolOffer()?->getId());
        self::assertInstanceOf(RiderPoolOffer::class, $offer);
        $offer->setEscalationStage(RiderPoolEscalationStage::CUSTOMER_NOTIFIED);
        $entityManager->flush();

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$order->getId().'/cancel', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $cancelled = $this->reload((string) $order->getId());
        self::assertSame(OrderStatus::CANCELLED_BY_CUSTOMER, $cancelled->getStatus());
        self::assertSame(PaymentStatus::FAILED, $cancelled->getPayment()?->getStatus());
        self::assertSame('0.00', $cancelled->getPayment()?->getRefundedAmount());
    }

    private function createFixture(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186200001', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Cancellation Test Address')
            ->setAddressLine('Test address')
            ->setLatitude('14.6000000')
            ->setLongitude('120.9850000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186200002', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Cancellation Test Restaurant')
            ->setSlug('cancellation-test-restaurant')
            ->setAddress('Test restaurant')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        foreach ([$customerUser, $customer, $address, $restaurantUser, $restaurant] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $this->customerId = (string) $customer->getId();
        $this->addressId = (string) $address->getId();
        $this->restaurantId = (string) $restaurant->getId();
    }

    private function createOrder(
        OrderStatus $status,
        PaymentMethod $method,
        PaymentStatus $paymentStatus,
        ?RiderPoolEscalationStage $stage = null,
    ): Order {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $order = (new Order())
            ->setOrderNumber('CANCEL-'.bin2hex(random_bytes(5)))
            ->setCustomer($entityManager->find(Customer::class, $this->customerId))
            ->setRestaurant($entityManager->find(Restaurant::class, $this->restaurantId))
            ->setCustomerAddress($entityManager->find(CustomerAddress::class, $this->addressId))
            ->setStatus($status)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod($method)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($method)
            ->setStatus($paymentStatus)
            ->setAmount('135.00')
            ->setPaidAt(PaymentStatus::PAID === $paymentStatus ? $now : null)
            ->setTransactionReference(PaymentStatus::PAID === $paymentStatus ? 'SIMULATED-CANCEL' : null)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setPayment($payment);
        $entityManager->persist($order);
        $entityManager->persist($payment);
        if ($stage instanceof RiderPoolEscalationStage) {
            $offer = (new RiderPoolOffer())
                ->setOrder($order)
                ->setEscalationStage($stage)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $order->setRiderPoolOffer($offer);
            $entityManager->persist($offer);
        }
        $entityManager->flush();

        return $order;
    }

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Cancellation Test User')
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
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::CUSTOMER_EMAIL, 'password' => self::PASSWORD]);
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

    private function reload(string $id): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $id);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ([
            'DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?))',
            'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM rider_pool_offers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM orders WHERE order_number LIKE ?',
        ] as $sql) {
            $connection->executeStatement($sql, ['CANCEL-%']);
        }
        $connection->executeStatement('DELETE FROM customer_addresses WHERE label = ?', ['Cancellation Test Address']);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?)', [self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
