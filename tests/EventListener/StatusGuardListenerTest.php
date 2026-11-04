<?php

namespace App\Tests\EventListener;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Order.status and Payment.status may only change through their transition services;
 * StatusGuardListener rejects any other write when the change is flushed.
 */
class StatusGuardListenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDirectOrderStatusChangeIsRejected(): void
    {
        $order = $this->createOrder();

        $order->setStatus(OrderStatus::DELIVERED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside OrderTransitionService');
        $this->entityManager->flush();
    }

    public function testDirectPaymentStatusChangeIsRejected(): void
    {
        $payment = $this->createOrder()->getPayment();
        self::assertInstanceOf(Payment::class, $payment);

        $payment->setStatus(PaymentStatus::PAID);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside PaymentTransitionService');
        $this->entityManager->flush();
    }

    public function testTransitionServicesMayChangeStatus(): void
    {
        $order = $this->createOrder();
        $payment = $order->getPayment();
        self::assertInstanceOf(Payment::class, $payment);

        $order = self::getContainer()->get(OrderTransitionService::class)
            ->transition($order, OrderStatus::ACCEPTED, OrderActor::RESTAURANT);
        self::getContainer()->get(PaymentTransitionService::class)
            ->markPaid($payment, PaymentActor::ADMIN, 'Collected.', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Order::class, $order->getId());
        self::assertSame(OrderStatus::ACCEPTED, $reloaded?->getStatus());
        self::assertSame(PaymentStatus::PAID, $reloaded?->getPayment()?->getStatus());
    }

    public function testAuthorizationCoversOnlyTheStatusTheServiceSet(): void
    {
        $order = self::getContainer()->get(OrderTransitionService::class)
            ->transition($this->createOrder(), OrderStatus::ACCEPTED, OrderActor::RESTAURANT);

        // The ACCEPTED authorization was used by the service's own flush; a later direct write is still rejected.
        $order->setStatus(OrderStatus::PREPARING);

        $this->expectException(\LogicException::class);
        $this->entityManager->flush();
    }

    private function createOrder(): Order
    {
        $now = new \DateTimeImmutable();
        $suffix = bin2hex(random_bytes(4));
        $restaurantUser = $this->user("guard-restaurant-$suffix@foodjett.test", UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Guard Kitchen')
            ->setSlug('guard-kitchen-'.$suffix)
            ->setAddress('Restaurant address')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user("guard-customer-$suffix@foodjett.test", UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Guard test')
            ->setAddressLine('Customer address')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order = (new Order())
            ->setOrderNumber('GUARD-TEST-'.$suffix)
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setCustomerAddress($address)
            ->setStatus(OrderStatus::PLACED)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod(PaymentMethod::COD)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod(PaymentMethod::COD)
            ->setStatus(PaymentStatus::PENDING)
            ->setAmount('135.00')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setPayment($payment);

        foreach ([$restaurantUser, $restaurant, $customerUser, $customer, $address, $order, $payment] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return $order;
    }

    private function user(string $email, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Guard Test')
            ->setEmail($email)
            ->setPassword('not-used')
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }
}
