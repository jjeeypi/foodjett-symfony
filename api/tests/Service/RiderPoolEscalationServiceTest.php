<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\RiderPoolOffer;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderPoolEscalationStage;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\RiderPoolEscalationService;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use App\Service\PlatformSettingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RiderPoolEscalationServiceTest extends KernelTestCase
{
    private const CUSTOMER_EMAIL = 'escalation-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'escalation-restaurant@foodjett.test';

    private EntityManagerInterface $entityManager;
    private Customer $customer;
    private CustomerAddress $address;
    private Restaurant $restaurant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testOverdueOfferCatchesUpThroughCustomerNotified(): void
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder('ESCALATION-NOTIFIED', PaymentMethod::COD, PaymentStatus::PENDING, $now->modify('-9 minutes'));

        $changed = $this->service()->processOrder($order, $now);

        self::assertTrue($changed);
        $order = $this->reload((string) $order->getId());
        self::assertSame(OrderStatus::FINDING_RIDER, $order->getStatus());
        self::assertSame(RiderPoolEscalationStage::CUSTOMER_NOTIFIED, $order->getRiderPoolOffer()?->getEscalationStage());
        self::assertSame('8.0', $order->getRiderPoolOffer()?->getSearchRadiusKm());
        self::assertSame('20.00', $order->getRiderPoolOffer()?->getIncentiveAmount());
        self::assertTrue($order->canBeCancelledByCustomer());
        self::assertCount(4, $order->getStatusHistory());
    }

    public function testAutoCancellationRefundsPaidSimulatedPayment(): void
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder('ESCALATION-CARD', PaymentMethod::CARD, PaymentStatus::PAID, $now->modify('-16 minutes'));

        self::assertTrue($this->service()->processOrder($order, $now));

        $order = $this->reload((string) $order->getId());
        self::assertSame(OrderStatus::CANCELLED_NO_RIDER, $order->getStatus());
        self::assertSame(OrderActor::SYSTEM, $order->getCancelledBy());
        self::assertSame(RiderPoolEscalationStage::AUTO_CANCELLED, $order->getRiderPoolOffer()?->getEscalationStage());
        self::assertSame(PaymentStatus::REFUNDED, $order->getPayment()?->getStatus());
        self::assertSame($order->getPayment()?->getAmount(), $order->getPayment()?->getRefundedAmount());
        self::assertCount(1, $order->getPayment()?->getStatusHistory());
    }

    public function testAutoCancellationMarksUncollectedCodPaymentFailed(): void
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder('ESCALATION-COD', PaymentMethod::COD, PaymentStatus::PENDING, $now->modify('-16 minutes'));

        self::assertTrue($this->service()->processOrder($order, $now));

        $order = $this->reload((string) $order->getId());
        self::assertSame(OrderStatus::CANCELLED_NO_RIDER, $order->getStatus());
        self::assertSame(PaymentStatus::FAILED, $order->getPayment()?->getStatus());
        self::assertSame('0.00', $order->getPayment()?->getRefundedAmount());
    }

    private function createFixture(): void
    {
        $now = new \DateTimeImmutable();
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186600001', UserRole::CUSTOMER, $now);
        $this->customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $this->address = (new CustomerAddress())
            ->setCustomer($this->customer)
            ->setLabel('Escalation Test Address')
            ->setAddressLine('Escalation delivery')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186600002', UserRole::RESTAURANT, $now);
        $this->restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Escalation Test Restaurant')
            ->setSlug('escalation-test-restaurant')
            ->setAddress('Escalation pickup')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        foreach ([$customerUser, $this->customer, $this->address, $restaurantUser, $this->restaurant] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    private function createOrder(
        string $number,
        PaymentMethod $method,
        PaymentStatus $paymentStatus,
        \DateTimeImmutable $startedAt,
    ): Order {
        $now = new \DateTimeImmutable();
        $order = (new Order())
            ->setOrderNumber($number)
            ->setCustomer($this->customer)
            ->setRestaurant($this->restaurant)
            ->setCustomerAddress($this->address)
            ->setStatus(OrderStatus::FINDING_RIDER)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod($method)
            ->setRiderSearchStartedAt($startedAt)
            ->setPlacedAt($startedAt)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $offer = (new RiderPoolOffer())
            ->setOrder($order)
            ->setSearchRadiusKm('3.0')
            ->setEscalationStage(RiderPoolEscalationStage::INITIAL)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($method)
            ->setStatus($paymentStatus)
            ->setAmount('135.00')
            ->setPaidAt(PaymentStatus::PAID === $paymentStatus ? $now : null)
            ->setTransactionReference(PaymentStatus::PAID === $paymentStatus ? 'SIMULATED-ESCALATION' : null)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setRiderPoolOffer($offer)->setPayment($payment);
        foreach ([$order, $offer, $payment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return $order;
    }

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Escalation Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword('not-used')
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function service(): RiderPoolEscalationService
    {
        $settings = new PlatformSettingService($this->entityManager);

        return new RiderPoolEscalationService(
            $this->entityManager,
            $settings,
            new OrderTransitionService($this->entityManager, $settings, new EventDispatcher()),
            new PaymentTransitionService($this->entityManager),
        );
    }

    private function reload(string $id): Order
    {
        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $id);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function cleanup(): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ([
            'DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?))',
            'DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM rider_pool_declines WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM rider_pool_offers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE ?)',
            'DELETE FROM orders WHERE order_number LIKE ?',
        ] as $sql) {
            $connection->executeStatement($sql, ['ESCALATION-%']);
        }
        $connection->executeStatement('DELETE FROM customer_addresses WHERE label = ?', ['Escalation Test Address']);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?)', [self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
