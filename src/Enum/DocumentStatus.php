<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';
}
