<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

final class RecordingMercureHub implements HubInterface
{
    /** @var list<Update> */
    private array $updates = [];
    private readonly TokenFactoryInterface $factory;

    public function __construct()
    {
        $this->factory = new DefaultClaimsTokenFactory(
            new LcobucciFactory('foodjett-mercure-test-secret-key-32-bytes'),
            [
                'iss' => 'foodjett-api',
                'aud' => $this->getPublicUrl(),
                'sub' => 'foodjett-api',
                'client_id' => 'foodjett-api',
            ],
        );
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost:3000/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->factory;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return 'test-update-'.count($this->updates);
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercure_access_token';
    }

    /** @return list<Update> */
    public function updates(): array
    {
        return $this->updates;
    }

    public function reset(): void
    {
        $this->updates = [];
    }
}
