<?php

declare(strict_types=1);

namespace App\Enum;

enum RiderPoolDeclineAction: string
{
    case SKIPPED = 'skipped';
    case EXPIRED = 'expired';
}
