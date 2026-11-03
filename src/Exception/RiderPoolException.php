<?php

declare(strict_types=1);

namespace App\Exception;

final class RiderPoolException extends \DomainException
{
    public function __construct(string $message, private readonly int $statusCode = 409)
    {
        parent::__construct($message);
    }

    public static function taken(): self
    {
        return new self('This order was just taken by another rider.');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
