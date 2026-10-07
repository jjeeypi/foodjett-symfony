<?php

declare(strict_types=1);

namespace App\Enum;

enum ConversationType: string
{
    case CUSTOMER_RIDER = 'customer_rider';
    case CUSTOMER_RESTAURANT = 'customer_restaurant';
}
