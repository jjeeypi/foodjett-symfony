<?php

namespace App\Message;


final class OrderUpdated
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $currentStatus,
        public readonly string $changedBy,
        public readonly string $kind,
        public readonly ?string $note,
        public readonly \DateTimeImmutable $changedAt,
    ) {
    }
}
