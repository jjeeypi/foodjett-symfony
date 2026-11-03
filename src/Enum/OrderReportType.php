<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderReportType: string
{
    case MISSING_ITEM = 'missing_item';
    case WRONG_ITEM = 'wrong_item';
    case LATE_DELIVERY = 'late_delivery';
    case NO_SHOW_RIDER = 'no_show_rider';
    case RUDE_BEHAVIOR = 'rude_behavior';
    case CUSTOMER_UNREACHABLE = 'customer_unreachable';
    case ACCIDENT = 'accident';
    case OTHER = 'other';
}
