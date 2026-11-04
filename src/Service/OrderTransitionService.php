<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\RiderPoolOffer;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\RiderPoolEscalationStage;
use App\Message\OrderStatusChanged;
use App\Message\OrderUpdated;
use App\Message\RiderPoolOfferCreated;
use App\Message\RiderPoolOfferTaken;
use App\Repository\PlatformSettingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only writer of Order.status. Every status it sets is recorded as authorized so that
 * StatusGuardListener can reject any other change to the column at flush time.
 */
final class OrderTransitionService
{
    /**
     * Statuses this service set and has not yet seen flushed, keyed by entity. Shared by every
     * instance in the process; a WeakMap so entries disappear together with their entities.
     *
     * @var \WeakMap<Order, OrderStatus>|null
     */
    private static ?\WeakMap $authorizedStatuses = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlatformSettingRepository $settings,
        private readonly MessageBusInterface $bus,
    ) {
        self::$authorizedStatuses ??= new \WeakMap();
    }

    /**
     * Whether this service set $order's status to $status; a matching authorization is used up.
     */
    public function consumeAuthorizedStatus(Order $order, OrderStatus $status): bool
    {
        if ((self::$authorizedStatuses[$order] ?? null) !== $status) {
            return false;
        }
        unset(self::$authorizedStatuses[$order]);

        return true;
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

        /** @var list<OrderStatusChanged> $events */
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
                    throw new \DomainException(sprintf('Order cannot transition from "%s" to "%s".', $from->value, $status->value));
                }

                $note = $notes[$index] ?? null;
                $this->applyStatusSideEffects($locked, $status, $now);
                self::$authorizedStatuses[$locked] = $status;
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

                $events[] = new OrderStatusChanged(
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
            $this->bus->dispatch($event);
            if (OrderStatus::FINDING_RIDER === $event->currentStatus) {
                $this->bus->dispatch(new RiderPoolOfferCreated($event->orderId, $event->changedAt));
            } elseif (OrderStatus::FINDING_RIDER === $event->previousStatus) {
                $this->bus->dispatch(new RiderPoolOfferTaken(
                    $event->orderId,
                    $event->currentStatus->value,
                    $event->changedAt,
                ));
            }
        }

        return $updatedOrder;
    }

    /**
     * Records an order-domain update that does not replace the current delivery status.
     * This supports parallel food milestones such as ready while rider search is active.
     *
     * @param callable(Order, \DateTimeImmutable): void $applyChanges
     */
    public function recordUpdate(
        Order $order,
        OrderActor $changedBy,
        string $kind,
        ?string $note,
        callable $applyChanges,
        ?OrderStatus $historyStatus = null,
    ): Order {
        if (null === $order->getId()) {
            throw new \InvalidArgumentException('An order must be persisted before it can be updated.');
        }

        $changedAt = new \DateTimeImmutable();
        /** @var Order $updatedOrder */
        $updatedOrder = $this->entityManager->wrapInTransaction(function () use (
            $order,
            $changedBy,
            $note,
            $applyChanges,
            $historyStatus,
            $changedAt,
        ): Order {
            $locked = $this->entityManager->find(Order::class, $order->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof Order) {
                throw new \RuntimeException('Order no longer exists.');
            }

            $applyChanges($locked, $changedAt);
            $locked->setUpdatedAt($changedAt);
            $history = (new OrderStatusHistory())
                ->setOrder($locked)
                ->setStatus(($historyStatus ?? $locked->getStatus())->value)
                ->setChangedBy($changedBy)
                ->setNote($note)
                ->setCreatedAt($changedAt);
            $locked->addStatusHistory($history);
            $this->entityManager->persist($history);
            $this->entityManager->flush();

            return $locked;
        });

        $this->bus->dispatch(new OrderUpdated(
            orderId: (string) $updatedOrder->getId(),
            currentStatus: $updatedOrder->getStatus(),
            changedBy: $changedBy,
            kind: $kind,
            note: $note,
            changedAt: $changedAt,
        ));

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
                throw new \DomainException(sprintf('Order cannot transition from "%s" to "%s".', $from->value, $status->value));
            }
            $from = $status;
        }
    }
}
