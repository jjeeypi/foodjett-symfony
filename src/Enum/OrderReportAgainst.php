<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderReportAgainst: string
{
    case RESTAURANT = 'restaurant';
    case RIDER = 'rider';
    case CUSTOMER = 'customer';
    case PLATFORM = 'platform';
}
