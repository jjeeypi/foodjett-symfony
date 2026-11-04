<?php

namespace App\Message;

use App\Enum\OrderActor;
use App\Enum\OrderStatus;

final class OrderStatusChanged
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?OrderStatus $previousStatus,
        public readonly OrderStatus $currentStatus,
        public readonly OrderActor $changedBy,
        public readonly ?string $note,
        public readonly \DateTimeImmutable $changedAt,
    ) {
    }
}
