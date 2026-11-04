<?php

namespace App\Message;

use App\Enum\OrderActor;
use App\Enum\OrderStatus;

final class OrderUpdated
{
    public function __construct(
        public readonly string $orderId,
        public readonly OrderStatus $currentStatus,
        public readonly OrderActor $changedBy,
        public readonly string $kind,
        public readonly ?string $note,
        public readonly \DateTimeImmutable $changedAt,
    ) {
    }
}
