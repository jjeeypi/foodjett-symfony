<?php

declare(strict_types=1);

namespace App\Event;

final readonly class RiderLocationUpdatedEvent
{
    public function __construct(
        public string $orderId,
        public string $riderId,
        public string $latitude,
        public string $longitude,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
