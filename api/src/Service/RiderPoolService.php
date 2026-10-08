<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\RiderPoolDecline;
use App\Entity\RiderPoolOffer;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\RiderPoolDeclineAction;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RiderPoolService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RiderPayCalculator $payCalculator,
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
                'estimated_pay' => $this->payCalculator->estimate($pickupDistance, $deliveryDistance, $offer),
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
