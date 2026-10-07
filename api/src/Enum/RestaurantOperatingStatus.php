<?php

declare(strict_types=1);

namespace App\Enum;

enum RestaurantOperatingStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case TEMPORARILY_CLOSED = 'temporarily_closed';
}
