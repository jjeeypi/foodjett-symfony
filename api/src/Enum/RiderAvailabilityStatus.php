<?php

declare(strict_types=1);

namespace App\Enum;

enum RiderAvailabilityStatus: string
{
    case OFFLINE = 'offline';
    case AVAILABLE = 'available';
    case BUSY = 'busy';
}
