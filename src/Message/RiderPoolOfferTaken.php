<?php

namespace App\Message;

final class RiderPoolOfferTaken
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
