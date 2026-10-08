<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\OrderStatusChangedEvent;
use App\Exception\InvalidOrderTransitionException;
use App\Service\OrderTransitionService;
use App\Service\PlatformSettingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class OrderTransitionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->cleanup();
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testAcceptanceSequenceCreatesPoolHistoryAndDispatchesOnlyAfterCommit(): void
    {
        $order = $this->createOrder();
        $dispatcher = new EventDispatcher();
        $events = [];
        $transactionStates = [];
        $dispatcher->addListener(OrderStatusChangedEvent::class, function (OrderStatusChangedEvent $event) use (&$events, &$transactionStates): void {
            $events[] = $event;
            $transactionStates[] = $this->entityManager->getConnection()->isTransactionActive();
        });
        $service = new OrderTransitionService(
            $this->entityManager,
            new PlatformSettingService($this->entityManager),
            $dispatcher,
        );

        $updated = $service->transitionSequence(
            $order,
            [OrderStatus::ACCEPTED, OrderStatus::PREPARING, OrderStatus::FINDING_RIDER],
            OrderActor::RESTAURANT,
            ['Accepted by restaurant', 'Food preparation started', 'Rider search started'],
            static function (Order $locked, \DateTimeImmutable $now): void {
                $locked
                    ->setAcceptedAt($now)
                    ->setEstimatedPrepMinutes(20)
                    ->setEstimatedReadyAt($now->modify('+20 minutes'));
            },
        );

        self::assertSame(OrderStatus::FINDING_RIDER, $updated->getStatus());
        self::assertNotNull($updated->getRiderSearchStartedAt());
        self::assertSame(20, $updated->getEstimatedPrepMinutes());
        self::assertSame('3.0', $updated->getRiderPoolOffer()?->getSearchRadiusKm());
        self::assertSame(3, $updated->getStatusHistory()->count());
        self::assertCount(3, $events);
        self::assertSame(
            [OrderStatus::ACCEPTED, OrderStatus::PREPARING, OrderStatus::FINDING_RIDER],
            array_map(static fn (OrderStatusChangedEvent $event): OrderStatus => $event->currentStatus, $events),
        );
        self::assertSame([false, false, false], $transactionStates);
    }

    public function testInvalidTransitionIsRejectedWithoutHistory(): void
    {
        $order = $this->createOrder();
        $service = new OrderTransitionService(
            $this->entityManager,
            new PlatformSettingService($this->entityManager),
            new EventDispatcher(),
        );

        try {
            $service->transition($order, OrderStatus::DELIVERED, OrderActor::SYSTEM);
            self::fail('An invalid transition should throw.');
        } catch (InvalidOrderTransitionException $exception) {
            self::assertSame('Order cannot transition from "placed" to "delivered".', $exception->getMessage());
        }

        $fresh = $this->entityManager->getRepository(Order::class)->findOneBy(['orderNumber' => $order->getOrderNumber()]);
        self::assertInstanceOf(Order::class, $fresh);
        self::assertSame(OrderStatus::PLACED, $fresh->getStatus());
        self::assertCount(0, $fresh->getStatusHistory());
    }

    #[DataProvider('terminalStatuses')]
    public function testTerminalStatusesCannotTransition(OrderStatus $status): void
    {
        self::assertTrue($status->isTerminal());
        self::assertSame([], $status->allowedTransitions());
    }

    /** @return iterable<string, array{OrderStatus}> */
    public static function terminalStatuses(): iterable
    {
        foreach ([
            OrderStatus::DELIVERED,
            OrderStatus::REJECTED_BY_RESTAURANT,
            OrderStatus::CANCELLED_BY_CUSTOMER,
            OrderStatus::CANCELLED_BY_RESTAURANT,
            OrderStatus::CANCELLED_NO_RIDER,
            OrderStatus::CANCELLED_BY_ADMIN,
            OrderStatus::FAILED_DELIVERY,
        ] as $status) {
            yield $status->value => [$status];
        }
    }

    private function createOrder(): Order
    {
        $now = new \DateTimeImmutable();
        $restaurantUser = $this->user('transition-restaurant@foodjett.test', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Transition Kitchen')
            ->setSlug('transition-kitchen')
            ->setAddress('Restaurant address')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $customerUser = $this->user('transition-customer@foodjett.test', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Transition test')
            ->setAddressLine('Customer address')
            ->setLatitude('14.6091000')
            ->setLongitude('121.0223000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $order = (new Order())
            ->setOrderNumber('TRANS-TEST-'.bin2hex(random_bytes(4)))
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

        foreach ([$restaurantUser, $restaurant, $customerUser, $customer, $address, $order] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return $order;
    }

    private function user(string $email, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Transition Test')
            ->setEmail($email)
            ->setPassword('not-used')
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function cleanup(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("DELETE FROM rider_pool_offers WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'TRANS-TEST-%')");
        $connection->executeStatement("DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'TRANS-TEST-%')");
        $connection->executeStatement("DELETE FROM orders WHERE order_number LIKE 'TRANS-TEST-%'");
        $connection->executeStatement("DELETE FROM customer_addresses WHERE label = 'Transition test'");
        $connection->executeStatement("DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'transition-%@foodjett.test')");
        $connection->executeStatement("DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'transition-%@foodjett.test')");
        $connection->executeStatement("DELETE FROM users WHERE email LIKE 'transition-%@foodjett.test'");
        $this->entityManager->clear();
    }
}
