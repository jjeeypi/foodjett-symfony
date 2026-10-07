<?php

declare(strict_types=1);

namespace App\Enum;

enum PayoutMethod: string
{
    case BANK = 'bank';
    case EWALLET = 'ewallet';
}
