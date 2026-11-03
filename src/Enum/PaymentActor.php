<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentActor: string
{
    case CUSTOMER = 'customer';
    case RIDER = 'rider';
    case ADMIN = 'admin';
    case SYSTEM = 'system';
}
