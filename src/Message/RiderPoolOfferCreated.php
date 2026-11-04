<?php

namespace App\Message;

final class RiderPoolOfferCreated
{
    public function __construct(
        public readonly string $orderId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
