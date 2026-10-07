<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case REFUNDED = 'refunded';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case FAILED = 'failed';
}
