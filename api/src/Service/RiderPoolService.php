<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\RiderPoolDecline;
use App\Entity\RiderPoolOffer;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\RiderPoolDeclineAction;
use App\Exception\RiderPoolException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RiderPoolService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RiderPayCalculator $payCalculator,
        private OrderTransitionService $transitions,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleOrders(Rider $rider): array
    {
        if (RiderAvailabilityStatus::AVAILABLE !== $rider->getAvailabilityStatus()
            || null === $rider->getCurrentLatitude()
            || null === $rider->getCurrentLongitude()) {
            return [];
        }

        /** @var list<Order> $orders */
        $orders = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->innerJoin('orders.riderPoolOffer', 'offer')
            ->addSelect('offer')
            ->innerJoin('orders.restaurant', 'restaurant')
            ->addSelect('restaurant')
            ->innerJoin('orders.customerAddress', 'address')
            ->addSelect('address')
            ->andWhere('orders.status = :status')
            ->andWhere('orders.rider IS NULL')
            ->setParameter('status', OrderStatus::FINDING_RIDER)
            ->orderBy('orders.riderSearchStartedAt', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        $visible = [];
        foreach ($orders as $order) {
            $offer = $order->getRiderPoolOffer();
            if (!$offer instanceof RiderPoolOffer || $this->wasSkipped($order, $rider)) {
                continue;
            }
            if (PaymentMethod::COD === $order->getPaymentMethod() && $this->hasReachedCashLimit($rider)) {
                continue;
            }

            $pickupDistance = DeliveryZoneService::distanceKm(
                (float) $rider->getCurrentLatitude(),
                (float) $rider->getCurrentLongitude(),
                (float) $order->getRestaurant()->getLatitude(),
                (float) $order->getRestaurant()->getLongitude(),
            );
            if ($pickupDistance > (float) $offer->getSearchRadiusKm()) {
                continue;
            }
            $deliveryDistance = DeliveryZoneService::distanceKm(
                (float) $order->getRestaurant()->getLatitude(),
                (float) $order->getRestaurant()->getLongitude(),
                (float) $order->getCustomerAddress()->getLatitude(),
                (float) $order->getCustomerAddress()->getLongitude(),
            );
            $pay = $this->payCalculator->estimate($pickupDistance, $deliveryDistance, $offer);

            $visible[] = [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'restaurant' => [
                    'name' => $order->getRestaurant()->getName(),
                    'latitude' => $order->getRestaurant()->getLatitude(),
                    'longitude' => $order->getRestaurant()->getLongitude(),
                ],
                'pickup_distance_km' => $this->distance($pickupDistance),
                'delivery_distance_km' => $this->distance($deliveryDistance),
                'estimated_pay' => $pay['total'],
                'pay_breakdown' => $pay,
                'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
                'search_radius_km' => $offer->getSearchRadiusKm(),
                'escalation_stage' => $offer->getEscalationStage()->value,
                'payment_method' => $order->getPaymentMethod()->value,
            ];
        }

        return $visible;
    }

    public function hasReachedCashLimit(Rider $rider): bool
    {
        return $this->moneyToCents($rider->getCashOnHand()) >= $this->moneyToCents($rider->getCashRemitLimit());
    }

    public function accept(Order $order, Rider $rider, bool $adminOverride = false): Order
    {
        if (null === $rider->getId()) {
            throw new \InvalidArgumentException('The rider must be persisted before accepting an order.');
        }

        return $this->transitions->transition(
            $order,
            OrderStatus::RIDER_ASSIGNED,
            $adminOverride ? OrderActor::ADMIN : OrderActor::RIDER,
            $adminOverride ? 'Rider manually assigned by admin.' : 'Rider accepted the pool offer.',
            function (Order $lockedOrder, \DateTimeImmutable $now) use ($rider, $adminOverride): void {
                $lockedRider = $this->entityManager->find(Rider::class, $rider->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$lockedRider instanceof Rider) {
                    throw new RiderPoolException('Rider profile not found.', 404);
                }
                if (OrderStatus::FINDING_RIDER !== $lockedOrder->getStatus() || null !== $lockedOrder->getRider()) {
                    throw RiderPoolException::taken();
                }

                $this->assertEligible($lockedOrder, $lockedRider, $adminOverride);
                $lockedOrder->setRider($lockedRider);
                $lockedRider
                    ->setAvailabilityStatus(RiderAvailabilityStatus::BUSY)
                    ->setUpdatedAt($now);
                if ($adminOverride) {
                    $lockedOrder->getRiderPoolOffer()?->setAdminAssigned(true)->setUpdatedAt($now);
                }
            },
        );
    }

    public function decline(Order $order, Rider $rider): RiderPoolDecline
    {
        if (null === $order->getId() || null === $rider->getId()) {
            throw new \InvalidArgumentException('The order and rider must be persisted before declining.');
        }

        /** @var RiderPoolDecline $decline */
        $decline = $this->entityManager->wrapInTransaction(function () use ($order, $rider): RiderPoolDecline {
            $lockedOrder = $this->entityManager->find(Order::class, $order->getId(), LockMode::PESSIMISTIC_WRITE);
            $lockedRider = $this->entityManager->find(Rider::class, $rider->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedOrder instanceof Order || !$lockedRider instanceof Rider) {
                throw new RiderPoolException('Order or rider no longer exists.', 404);
            }
            if (OrderStatus::FINDING_RIDER !== $lockedOrder->getStatus() || null !== $lockedOrder->getRider()) {
                throw RiderPoolException::taken();
            }
            $this->assertEligible($lockedOrder, $lockedRider, false, checkActiveOrder: false, checkCodLimit: false);

            $decline = $this->entityManager->getRepository(RiderPoolDecline::class)->findOneBy([
                'order' => $lockedOrder,
                'rider' => $lockedRider,
            ]);
            if (!$decline instanceof RiderPoolDecline) {
                $decline = (new RiderPoolDecline())
                    ->setOrder($lockedOrder)
                    ->setRider($lockedRider)
                    ->setCreatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($decline);
            }
            $decline->setAction(RiderPoolDeclineAction::SKIPPED);
            $this->entityManager->flush();

            return $decline;
        });

        return $decline;
    }

    private function assertEligible(
        Order $order,
        Rider $rider,
        bool $adminOverride,
        bool $checkActiveOrder = true,
        bool $checkCodLimit = true,
    ): void {
        if (ApprovalStatus::APPROVED !== $rider->getApprovalStatus()) {
            throw new RiderPoolException('Only approved riders can be assigned to orders.', 422);
        }
        if (null === $rider->getCurrentLatitude() || null === $rider->getCurrentLongitude()) {
            throw new RiderPoolException('Current rider location is required.', 422);
        }
        $offer = $order->getRiderPoolOffer();
        if (!$offer instanceof RiderPoolOffer) {
            throw new RiderPoolException('This order has no active rider pool offer.');
        }
        $distance = DeliveryZoneService::distanceKm(
            (float) $rider->getCurrentLatitude(),
            (float) $rider->getCurrentLongitude(),
            (float) $order->getRestaurant()->getLatitude(),
            (float) $order->getRestaurant()->getLongitude(),
        );
        if ($distance > (float) $offer->getSearchRadiusKm()) {
            throw new RiderPoolException('This order is outside the current search radius.');
        }
        if ($checkActiveOrder && $this->hasActiveOrder($rider, $order)) {
            throw new RiderPoolException('Finish your current delivery before accepting another order.');
        }
        if ($checkCodLimit && PaymentMethod::COD === $order->getPaymentMethod() && $this->hasReachedCashLimit($rider)) {
            throw new RiderPoolException('Remit cash before accepting another COD order.');
        }
        if (!$adminOverride && RiderAvailabilityStatus::AVAILABLE !== $rider->getAvailabilityStatus()) {
            throw new RiderPoolException('Go online before accepting an order.');
        }
    }

    private function hasActiveOrder(Rider $rider, Order $candidate): bool
    {
        $terminal = array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isTerminal())),
        );
        $builder = $this->entityManager->getRepository(Order::class)->createQueryBuilder('active')
            ->select('COUNT(active.id)')
            ->andWhere('active.rider = :rider')
            ->andWhere('active.status NOT IN (:terminal)')
            ->setParameter('rider', $rider)
            ->setParameter('terminal', $terminal);
        if (null !== $candidate->getId()) {
            $builder->andWhere('active.id != :candidate')->setParameter('candidate', $candidate->getId());
        }

        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }

    private function wasSkipped(Order $order, Rider $rider): bool
    {
        return $this->entityManager->getRepository(RiderPoolDecline::class)->findOneBy([
            'order' => $order,
            'rider' => $rider,
            'action' => RiderPoolDeclineAction::SKIPPED,
        ]) instanceof RiderPoolDecline;
    }

    private function distance(float $kilometres): string
    {
        return number_format(round($kilometres, 2), 2, '.', '');
    }

    private function moneyToCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
