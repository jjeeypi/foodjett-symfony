<?php

namespace App\Message;

final class RiderLocationUpdated
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $riderId,
        public readonly string $latitude,
        public readonly string $longitude,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
