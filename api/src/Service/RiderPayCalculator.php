<?php

declare(strict_types=1);

namespace App\Service;

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

    private function decimal(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
