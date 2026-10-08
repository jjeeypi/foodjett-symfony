<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\OrderActor;
use App\Enum\OrderStatus;

final readonly class OrderStatusChangedEvent
{
    public function __construct(
        public string $orderId,
        public OrderStatus $previousStatus,
        public OrderStatus $currentStatus,
        public OrderActor $changedBy,
        public ?string $note,
        public \DateTimeImmutable $changedAt,
    ) {
    }
}
