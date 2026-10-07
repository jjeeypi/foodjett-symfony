<?php

declare(strict_types=1);

namespace App\Enum;

enum VoucherType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';
    case FREE_DELIVERY = 'free_delivery';
}
