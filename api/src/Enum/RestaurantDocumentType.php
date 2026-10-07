<?php

declare(strict_types=1);

namespace App\Enum;

enum RestaurantDocumentType: string
{
    case BUSINESS_PERMIT = 'business_permit';
    case FOOD_SAFETY_PERMIT = 'food_safety_permit';
    case OWNER_ID = 'owner_id';
}
