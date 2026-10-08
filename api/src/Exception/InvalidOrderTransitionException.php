<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\OrderStatus;

final class InvalidOrderTransitionException extends \DomainException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf(
            'Order cannot transition from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
