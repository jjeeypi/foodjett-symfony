<?php

declare(strict_types=1);

namespace App\Enum;

enum RemittanceStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
}
