<?php

declare(strict_types=1);

namespace App\Exception;

final class CheckoutValidationException extends \DomainException
{
    public function __construct(string $message, private readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
