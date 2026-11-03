<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CorsTest extends WebTestCase
{
    public function testLocalViteOriginCanPreflightApiRequestWithCredentials(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost:5173', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $client->getResponse()->headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('POST', (string) $client->getResponse()->headers->get('Access-Control-Allow-Methods'));
    }

    public function testNonLocalOriginDoesNotReceiveCorsPermission(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => 'https://malicious.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        self::assertNull($client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
