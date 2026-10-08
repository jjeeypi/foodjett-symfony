<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\RiderPoolOffer;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\RiderPoolEscalationStage;
use App\Event\OrderStatusChangedEvent;
use App\Exception\InvalidOrderTransitionException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class OrderTransitionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformSettingService $settings,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param null|callable(Order, \DateTimeImmutable): void $applyChanges
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        OrderActor $changedBy,
        ?string $note = null,
        ?callable $applyChanges = null,
    ): Order {
        return $this->transitionSequence($order, [$to], $changedBy, [$note], $applyChanges);
    }

    /**
     * Atomically records a sequence such as accepted -> preparing -> finding_rider.
     * Events are deliberately dispatched only after wrapInTransaction() commits.
     *
     * @param non-empty-list<OrderStatus>                   $statuses
     * @param list<?string>                                 $notes
     * @param null|callable(Order, \DateTimeImmutable): void $applyChanges
     */
    public function transitionSequence(
        Order $order,
        array $statuses,
        OrderActor $changedBy,
        array $notes = [],
        ?callable $applyChanges = null,
    ): Order {
        if (null === $order->getId()) {
            throw new \InvalidArgumentException('An order must be persisted before it can transition.');
        }
        if ([] === $statuses) {
            throw new \InvalidArgumentException('At least one destination status is required.');
        }
        $this->assertValidSequence($order->getStatus(), $statuses);

        /** @var list<OrderStatusChangedEvent> $events */
        $events = [];

        /** @var Order $updatedOrder */
        $updatedOrder = $this->entityManager->wrapInTransaction(function () use (
            $order,
            $statuses,
            $changedBy,
            $notes,
            $applyChanges,
            &$events,
        ): Order {
            $locked = $this->entityManager->find(Order::class, $order->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof Order) {
                throw new \RuntimeException('Order no longer exists.');
            }

            $now = new \DateTimeImmutable();
            if (null !== $applyChanges) {
                $applyChanges($locked, $now);
            }

            foreach ($statuses as $index => $status) {
                $from = $locked->getStatus();
                if (!$from->canTransitionTo($status)) {
                    throw InvalidOrderTransitionException::between($from, $status);
                }

                $note = $notes[$index] ?? null;
                $this->applyStatusSideEffects($locked, $status, $now);
                $locked
                    ->setStatus($status)
                    ->setUpdatedAt($now);

                $history = (new OrderStatusHistory())
                    ->setOrder($locked)
                    ->setStatus($status->value)
                    ->setChangedBy($changedBy)
                    ->setNote($note)
                    ->setCreatedAt($now);
                $locked->addStatusHistory($history);
                $this->entityManager->persist($history);

                $events[] = new OrderStatusChangedEvent(
                    orderId: (string) $locked->getId(),
                    previousStatus: $from,
                    currentStatus: $status,
                    changedBy: $changedBy,
                    note: $note,
                    changedAt: $now,
                );
            }

            $this->entityManager->flush();

            return $locked;
        });

        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }

        return $updatedOrder;
    }

    private function applyStatusSideEffects(Order $order, OrderStatus $status, \DateTimeImmutable $now): void
    {
        if (OrderStatus::FINDING_RIDER === $status) {
            if (null === $order->getRiderSearchStartedAt()) {
                $order->setRiderSearchStartedAt($now);
            }
            if (null === $order->getRiderPoolOffer()) {
                $radius = max(0.1, $this->settings->float('rider_search_initial_radius_km', 3.0));
                $offer = (new RiderPoolOffer())
                    ->setOrder($order)
                    ->setSearchRadiusKm(number_format($radius, 1, '.', ''))
                    ->setEscalationStage(RiderPoolEscalationStage::INITIAL)
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);
                $order->setRiderPoolOffer($offer);
                $this->entityManager->persist($offer);
            }
        }

        if (OrderStatus::RIDER_ASSIGNED === $status) {
            if (null === $order->getRider()) {
                throw new \DomainException('A rider must be assigned before entering rider_assigned status.');
            }
            $order
                ->setRiderAssignedAt($now)
                ->setPickupCode((string) random_int(1000, 9999));
        }

        if (OrderStatus::DELIVERED === $status) {
            $order->setDeliveredAt($now);
        }
    }

    /** @param list<OrderStatus> $statuses */
    private function assertValidSequence(OrderStatus $from, array $statuses): void
    {
        foreach ($statuses as $status) {
            if (!$from->canTransitionTo($status)) {
                throw InvalidOrderTransitionException::between($from, $status);
            }
            $from = $status;
        }
    }
}
