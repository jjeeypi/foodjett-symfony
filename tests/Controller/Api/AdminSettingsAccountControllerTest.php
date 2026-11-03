<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSettingsAccountControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'settings-admin@foodjett.test';
    private const CREATED_EMAIL = 'created-admin@foodjett.test';

    private KernelBrowser $client;
    private string $adminId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        $this->adminId = $this->createAdmin();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testPlatformSettingsCanBeBulkUpsertedAndRead(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('PATCH', '/api/admin/platform-settings', ['settings' => ['admin_api_test_rate' => '12.50']], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $settings = array_column($this->payload()['settings'], 'value', 'key');
        self::assertSame('12.50', $settings['admin_api_test_rate']);

        $this->client->jsonRequest('PATCH', '/api/admin/platform-settings', ['settings' => ['rider_base_pay' => 'invalid']], server: $this->auth($token));
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeliveryZoneCrudAndToggleUseCircleShape(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/delivery-zones', [
            'name' => 'Admin API Zone', 'center' => [14.5995, 120.9842], 'radius_km' => 5, 'is_active' => true,
        ], server: $this->auth($token));
        self::assertResponseStatusCodeSame(201);
        $zone = $this->payload()['zone'];
        self::assertSame('circle', $zone['polygon']['type']);
        self::assertTrue($zone['is_active']);

        $this->client->jsonRequest('POST', '/api/admin/delivery-zones/'.$zone['id'].'/toggle', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['zone']['is_active']);
        $this->client->request('DELETE', '/api/admin/delivery-zones/'.$zone['id'], server: $this->auth($token));
        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminCreationAndSelfSuspensionGuard(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/admins', [
            'name' => 'Created Admin', 'email' => self::CREATED_EMAIL, 'phone' => '+639190400002', 'password' => self::PASSWORD,
        ], server: $this->auth($token));
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload()['admin'];
        self::assertSame('active', $created['status']);

        $this->client->jsonRequest('POST', '/api/admin/admins/'.$this->adminId.'/suspend', [], server: $this->auth($token));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('You cannot suspend your own admin account.', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/admin/admins/'.$created['id'].'/suspend', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['admin']['status']);
    }

    private function createAdmin(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $user = (new User())->setName('Settings Admin')->setEmail(self::ADMIN_EMAIL)->setPhone('+639190400001')
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))->setRole(UserRole::ADMIN)->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $admin = (new Admin())->setUser($user)->setCreatedAt($now)->setUpdatedAt($now);
        $entityManager->persist($user);
        $entityManager->persist($admin);
        $entityManager->flush();
        return (string) $admin->getId();
    }

    private function login(): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        return (string) $this->payload()['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array { return ['HTTP_AUTHORIZATION' => 'Bearer '.$token]; }
    /** @return array<string, mixed> */
    private function payload(): array { return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM audit_logs WHERE action LIKE 'platform_setting.%' OR action LIKE 'delivery_zone.%' OR action LIKE 'admin.%'");
        $connection->executeStatement("DELETE FROM platform_settings WHERE `key` = 'admin_api_test_rate'");
        $connection->executeStatement("DELETE FROM delivery_zones WHERE name = 'Admin API Zone'");
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))', [self::ADMIN_EMAIL, self::CREATED_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?)', [self::ADMIN_EMAIL, self::CREATED_EMAIL]);
    }
}
