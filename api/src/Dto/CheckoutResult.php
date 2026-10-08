<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Order;

final readonly class CheckoutResult
{
    public function __construct(
        public Order $order,
        public bool $created,
    ) {
    }
}
