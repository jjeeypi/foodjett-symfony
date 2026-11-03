<?php

declare(strict_types=1);

namespace App\Event;

final readonly class RiderPoolOfferTakenEvent
{
    public function __construct(
        public string $orderId,
        public string $reason,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
