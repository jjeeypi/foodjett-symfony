<?php

namespace App\Message;


final class OrderStatusChanged
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $previousStatus,
        public readonly string $currentStatus,
        public readonly string $changedBy,
        public readonly ?string $note,
        public readonly \DateTimeImmutable $changedAt,
    ) {
    }
}
