<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderActor: string
{
    case CUSTOMER = 'customer';
    case RESTAURANT = 'restaurant';
    case RIDER = 'rider';
    case ADMIN = 'admin';
    case SYSTEM = 'system';
}
