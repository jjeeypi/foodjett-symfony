<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\OrderActor;
use App\Enum\OrderStatus;

final readonly class OrderUpdatedEvent
{
    public function __construct(
        public string $orderId,
        public OrderStatus $currentStatus,
        public OrderActor $changedBy,
        public string $kind,
        public ?string $note,
        public \DateTimeImmutable $changedAt,
    ) {
    }
}
