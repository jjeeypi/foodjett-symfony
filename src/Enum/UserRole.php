<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case ADMIN = 'admin';
    case RESTAURANT = 'restaurant';
    case RIDER = 'rider';
    case CUSTOMER = 'customer';
}
