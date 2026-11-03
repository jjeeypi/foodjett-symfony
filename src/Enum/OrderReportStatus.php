<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderReportStatus: string
{
    case OPEN = 'open';
    case UNDER_REVIEW = 'under_review';
    case RESOLVED = 'resolved';
    case REJECTED = 'rejected';
}
