<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\RiderPoolOffer;

/**
 * Rider pay is computed with bcmath on exact decimal strings. Distances (km) and waiting time
 * (minutes) are measurements, not money, so they arrive as floats and are converted to fixed
 * decimals before they touch any amount.
 */
final readonly class RiderPayCalculator
{
    public const DEFAULT_BASE_PAY = '40.00';

    public const DEFAULT_DISTANCE_RATE_PER_KM = '10.00';

    public function __construct(private PlatformSettingService $settings)
    {
    }

    /** @return array{base_pay: string, distance_pay: string, incentive_pay: string, total: string} */
    public function estimate(float $pickupDistanceKm, float $deliveryDistanceKm, RiderPoolOffer $offer): array
    {
        $base = $this->settings->decimal('rider_base_pay', self::DEFAULT_BASE_PAY);
        $distance = $this->distancePay($pickupDistanceKm + $deliveryDistanceKm);
        $incentive = $this->nonNegative($offer->getIncentiveAmount());

        return [
            'base_pay' => $base,
            'distance_pay' => $distance,
            'incentive_pay' => $incentive,
            'total' => $this->sum($base, $distance, $incentive),
        ];
    }

    /** @return array{base_pay: string, distance_pay: string, waiting_pay: string, incentive_pay: string, tip_amount: string, total: string} */
    public function earning(Order $order, float $restaurantWaitMinutes): array
    {
        $base = $this->settings->decimal('rider_base_pay', self::DEFAULT_BASE_PAY);
        $pickupDistanceKm = $order->getRiderPoolOffer()?->getAcceptedPickupDistanceKm();
        if (null === $pickupDistanceKm) {
            throw new \DomainException('The accepted pickup distance is missing for this order.');
        }
        $deliveryDistanceKm = DeliveryZoneService::distanceKm(
            (float) $order->getRestaurant()->getLatitude(),
            (float) $order->getRestaurant()->getLongitude(),
            (float) $order->getCustomerAddress()->getLatitude(),
            (float) $order->getCustomerAddress()->getLongitude(),
        );
        $distance = $this->distancePay((float) $pickupDistanceKm + $deliveryDistanceKm);
        $threshold = $this->settings->decimal('rider_waiting_compensation_threshold_minutes', '5', 4);
        $waiting = -1 !== bccomp(sprintf('%.4F', $restaurantWaitMinutes), $threshold, 4)
            ? $this->settings->decimal('rider_waiting_compensation_amount', '10.00')
            : '0.00';
        $incentive = $this->nonNegative($order->getRiderPoolOffer()?->getIncentiveAmount() ?? '0');
        $tip = $this->nonNegative($order->getTipAmount());

        return [
            'base_pay' => $base, 'distance_pay' => $distance,
            'waiting_pay' => $waiting, 'incentive_pay' => $incentive,
            'tip_amount' => $tip, 'total' => $this->sum($base, $distance, $waiting, $incentive, $tip),
        ];
    }

    private function distancePay(float $kilometres): string
    {
        $distance = sprintf('%.4F', max(0.0, $kilometres));
        $perKm = $this->settings->decimal('rider_distance_pay_per_km', self::DEFAULT_DISTANCE_RATE_PER_KM);

        return bcround(bcmul($distance, $perKm, 6), 2);
    }

    private function nonNegative(string $amount): string
    {
        return -1 === bccomp($amount, '0', 2) ? '0.00' : bcround($amount, 2);
    }

    private function sum(string ...$amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }
}
