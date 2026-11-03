<?php

declare(strict_types=1);

namespace App\Enum;

enum RiderPoolEscalationStage: string
{
    case INITIAL = 'initial';
    case WIDENED = 'widened';
    case INCENTIVIZED = 'incentivized';
    case ADMIN_ALERTED = 'admin_alerted';
    case CUSTOMER_NOTIFIED = 'customer_notified';
    case AUTO_CANCELLED = 'auto_cancelled';
}
