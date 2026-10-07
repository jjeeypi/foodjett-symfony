<?php

declare(strict_types=1);

namespace App\Enum;

enum VoucherScope: string
{
    case PLATFORM = 'platform';
    case RESTAURANT = 'restaurant';
}
