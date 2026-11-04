<?php

namespace App\Tests\EventListener;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\User;
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

        $order->setStatus(Order::STATUS_DELIVERED);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside OrderTransitionService');
        $this->entityManager->flush();
    }

    public function testDirectPaymentStatusChangeIsRejected(): void
    {
        $payment = $this->createOrder()->getPayment();
        self::assertInstanceOf(Payment::class, $payment);

        $payment->setStatus(Payment::STATUS_PAID);

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
            ->transition($order, Order::STATUS_ACCEPTED, Order::ACTOR_RESTAURANT);
        self::getContainer()->get(PaymentTransitionService::class)
            ->markPaid($payment, Payment::ACTOR_ADMIN, 'Collected.', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Order::class, $order->getId());
        self::assertSame(Order::STATUS_ACCEPTED, $reloaded?->getStatus());
        self::assertSame(Payment::STATUS_PAID, $reloaded?->getPayment()?->getStatus());
    }

    public function testAuthorizationCoversOnlyTheStatusTheServiceSet(): void
    {
        $order = self::getContainer()->get(OrderTransitionService::class)
            ->transition($this->createOrder(), Order::STATUS_ACCEPTED, Order::ACTOR_RESTAURANT);

        // The ACCEPTED authorization was used by the service's own flush; a later direct write is still rejected.
        $order->setStatus(Order::STATUS_PREPARING);

        $this->expectException(\LogicException::class);
        $this->entityManager->flush();
    }

    private function createOrder(): Order
    {
        $now = new \DateTimeImmutable();
        $suffix = bin2hex(random_bytes(4));
        $restaurantUser = $this->user("guard-restaurant-$suffix@foodjett.test", User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Guard Kitchen')
            ->setSlug('guard-kitchen-'.$suffix)
            ->setAddress('Restaurant address')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customerUser = $this->user("guard-customer-$suffix@foodjett.test", User::ACCOUNT_CUSTOMER, $now);
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
            ->setStatus(Order::STATUS_PLACED)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod(Payment::METHOD_COD)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod(Payment::METHOD_COD)
            ->setStatus(Payment::STATUS_PENDING)
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

    private function user(string $email, string $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Guard Test')
            ->setEmail($email)
            ->setPassword('not-used')
            ->setRole($role)
            ->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }
}
