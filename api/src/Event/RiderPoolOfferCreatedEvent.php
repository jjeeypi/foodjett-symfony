<?php

declare(strict_types=1);

namespace App\Event;

final readonly class RiderPoolOfferCreatedEvent
{
    public function __construct(
        public string $orderId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
