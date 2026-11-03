<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Enum\RiderPoolEscalationStage;
use App\Exception\InvalidOrderTransitionException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RiderPoolEscalationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformSettingService $settings,
        private OrderTransitionService $transitions,
        private PaymentTransitionService $payments,
    ) {
    }

    public function processPendingOrders(?\DateTimeImmutable $now = null): int
    {
        /** @var list<Order> $orders */
        $orders = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->innerJoin('orders.riderPoolOffer', 'offer')
            ->addSelect('offer')
            ->andWhere('orders.status = :status')
            ->andWhere('orders.rider IS NULL')
            ->setParameter('status', OrderStatus::FINDING_RIDER)
            ->orderBy('orders.riderSearchStartedAt', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        $processed = 0;
        foreach ($orders as $order) {
            if ($this->processOrder($order, $now)) {
                ++$processed;
            }
        }

        return $processed;
    }

    /**
     * Advances every stage whose absolute elapsed threshold has already passed.
     * Returns true when at least one stage or the order status changed.
     */
    public function processOrder(Order $order, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $startedAt = $order->getRiderSearchStartedAt();
        $offer = $order->getRiderPoolOffer();
        if (OrderStatus::FINDING_RIDER !== $order->getStatus() || null !== $order->getRider() || null === $startedAt || null === $offer) {
            return false;
        }

        $elapsedSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
        $changed = false;
        while (OrderStatus::FINDING_RIDER === $order->getStatus() && null === $order->getRider()) {
            $stage = $offer->getEscalationStage();
            if (RiderPoolEscalationStage::AUTO_CANCELLED === $stage) {
                return $this->autoCancel($order) || $changed;
            }

            [$next, $setting, $default] = $this->nextStage($stage);
            $thresholdSeconds = max(0.0, $this->settings->float($setting, $default)) * 60;
            if ($elapsedSeconds < $thresholdSeconds) {
                break;
            }

            if (RiderPoolEscalationStage::AUTO_CANCELLED === $next) {
                return $this->autoCancel($order) || $changed;
            }

            try {
                $order = $this->transitions->recordUpdate(
                    $order,
                    OrderActor::SYSTEM,
                    'rider_pool_escalated',
                    sprintf('Rider pool escalated from %s to %s.', $stage->value, $next->value),
                    function (Order $locked, \DateTimeImmutable $changedAt) use ($stage, $next): void {
                        $lockedOffer = $locked->getRiderPoolOffer();
                        if (OrderStatus::FINDING_RIDER !== $locked->getStatus()
                            || null !== $locked->getRider()
                            || null === $lockedOffer
                            || $stage !== $lockedOffer->getEscalationStage()) {
                            throw new RiderPoolEscalationRaceException();
                        }

                        $lockedOffer
                            ->setEscalationStage($next)
                            ->setUpdatedAt($changedAt);
                        if (RiderPoolEscalationStage::WIDENED === $next) {
                            $radius = max(0.1, $this->settings->float('rider_search_widen_radius_km', 8.0));
                            $lockedOffer->setSearchRadiusKm(number_format($radius, 1, '.', ''));
                        } elseif (RiderPoolEscalationStage::INCENTIVIZED === $next) {
                            $incentive = max(0.0, $this->settings->float('rider_incentive_amount', 20.0));
                            $lockedOffer->setIncentiveAmount(number_format($incentive, 2, '.', ''));
                        }
                    },
                );
            } catch (RiderPoolEscalationRaceException) {
                return $changed;
            }
            $offer = $order->getRiderPoolOffer();
            if (null === $offer) {
                break;
            }
            $changed = true;
        }

        return $changed;
    }

    /** @return array{RiderPoolEscalationStage, string, float} */
    private function nextStage(RiderPoolEscalationStage $stage): array
    {
        return match ($stage) {
            RiderPoolEscalationStage::INITIAL => [RiderPoolEscalationStage::WIDENED, 'rider_search_widen_after_minutes', 2.0],
            RiderPoolEscalationStage::WIDENED => [RiderPoolEscalationStage::INCENTIVIZED, 'rider_incentive_after_minutes', 4.0],
            RiderPoolEscalationStage::INCENTIVIZED => [RiderPoolEscalationStage::ADMIN_ALERTED, 'admin_alert_after_minutes', 6.0],
            RiderPoolEscalationStage::ADMIN_ALERTED => [RiderPoolEscalationStage::CUSTOMER_NOTIFIED, 'customer_notify_after_minutes', 8.0],
            RiderPoolEscalationStage::CUSTOMER_NOTIFIED => [RiderPoolEscalationStage::AUTO_CANCELLED, 'auto_cancel_after_minutes', 15.0],
            RiderPoolEscalationStage::AUTO_CANCELLED => throw new \LogicException('An auto-cancelled offer has no next stage.'),
        };
    }

    private function autoCancel(Order $order): bool
    {
        try {
            $this->transitions->transition(
                $order,
                OrderStatus::CANCELLED_NO_RIDER,
                OrderActor::SYSTEM,
                'No rider accepted the order before the search deadline.',
                function (Order $locked, \DateTimeImmutable $now): void {
                    if (OrderStatus::FINDING_RIDER !== $locked->getStatus() || null !== $locked->getRider()) {
                        throw new RiderPoolEscalationRaceException();
                    }
                    $locked
                        ->setCancellationReason('No rider was found before the search deadline.')
                        ->setCancelledBy(OrderActor::SYSTEM);
                    $locked->getRiderPoolOffer()?->setEscalationStage(RiderPoolEscalationStage::AUTO_CANCELLED)->setUpdatedAt($now);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund(
                            $locked->getPayment(),
                            PaymentActor::SYSTEM,
                            'Order automatically cancelled because no rider was found.',
                            $now,
                        );
                    }
                },
            );
        } catch (RiderPoolEscalationRaceException|InvalidOrderTransitionException) {
            return false;
        }

        return true;
    }
}

final class RiderPoolEscalationRaceException extends \RuntimeException
{
}
