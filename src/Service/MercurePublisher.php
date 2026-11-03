<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class MercurePublisher
{
    public function __construct(private HubInterface $hub)
    {
    }

    /**
     * @param string|list<string> $topics
     * @param array<string, mixed> $payload
     */
    public function publish(array|string $topics, array $payload, bool $private = true, ?string $type = null): string
    {
        return $this->hub->publish(new Update(
            $topics,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $private,
            type: $type,
        ));
    }
}
