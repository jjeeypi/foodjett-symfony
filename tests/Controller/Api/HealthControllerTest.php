<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointIsPublicAndChecksTheDatabase(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');
        self::assertSame([
            'status' => 'ok',
            'database' => 'reachable',
        ], json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
