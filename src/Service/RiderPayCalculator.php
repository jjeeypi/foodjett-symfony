<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\RiderPoolOffer;

final readonly class RiderPayCalculator
{
    public const DEFAULT_BASE_PAY = 40.0;

    public const DEFAULT_DISTANCE_RATE_PER_KM = 10.0;

    public function __construct(private PlatformSettingService $settings)
    {
    }

    /** @return array{base_pay: string, distance_pay: string, incentive_pay: string, total: string} */
    public function estimate(float $pickupDistanceKm, float $deliveryDistanceKm, RiderPoolOffer $offer): array
    {
        $base = max(0.0, $this->settings->float('rider_base_pay', self::DEFAULT_BASE_PAY));
        $perKm = max(0.0, $this->settings->float('rider_distance_pay_per_km', self::DEFAULT_DISTANCE_RATE_PER_KM));
        $distance = max(0.0, $pickupDistanceKm + $deliveryDistanceKm) * $perKm;
        $incentive = max(0.0, (float) $offer->getIncentiveAmount());

        return [
            'base_pay' => $this->decimal($base),
            'distance_pay' => $this->decimal($distance),
            'incentive_pay' => $this->decimal($incentive),
            'total' => $this->decimal($base + $distance + $incentive),
        ];
    }

    /** @return array{base_pay: string, distance_pay: string, waiting_pay: string, incentive_pay: string, tip_amount: string, total: string} */
    public function earning(Order $order, float $restaurantWaitMinutes): array
    {
        $base = max(0.0, $this->settings->float('rider_base_pay', self::DEFAULT_BASE_PAY));
        $perKm = max(0.0, $this->settings->float('rider_distance_pay_per_km', self::DEFAULT_DISTANCE_RATE_PER_KM));
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
        $distance = (max(0.0, (float) $pickupDistanceKm) + $deliveryDistanceKm) * $perKm;
        $threshold = max(0.0, $this->settings->float('rider_waiting_compensation_threshold_minutes', 5.0));
        $waiting = $restaurantWaitMinutes >= $threshold
            ? max(0.0, $this->settings->float('rider_waiting_compensation_amount', 10.0))
            : 0.0;
        $incentive = max(0.0, (float) ($order->getRiderPoolOffer()?->getIncentiveAmount() ?? '0'));
        $tip = max(0.0, (float) $order->getTipAmount());

        return [
            'base_pay' => $this->decimal($base), 'distance_pay' => $this->decimal($distance),
            'waiting_pay' => $this->decimal($waiting), 'incentive_pay' => $this->decimal($incentive),
            'tip_amount' => $this->decimal($tip), 'total' => $this->decimal($base + $distance + $waiting + $incentive + $tip),
        ];
    }

    private function decimal(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
