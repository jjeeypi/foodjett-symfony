<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentMethod: string
{
    case COD = 'cod';
    case GCASH = 'gcash';
    case CARD = 'card';
}
