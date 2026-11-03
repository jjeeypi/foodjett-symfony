<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Enum\SupportTicketStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'content-admin@foodjett.test';
    private const CUSTOMER_EMAIL = 'support-customer@foodjett.test';

    private KernelBrowser $client;
    private string $ticketId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        $this->ticketId = $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testPlatformVoucherCrud(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/vouchers', [
            'code' => 'ADMINTEST20', 'type' => 'percentage', 'value' => 20, 'min_order_amount' => 100,
            'usage_limit_total' => 50, 'usage_limit_per_customer' => 1, 'is_active' => true,
        ], server: $this->auth($token));
        self::assertResponseStatusCodeSame(201);
        $voucher = $this->payload()['voucher'];
        self::assertSame('platform', $voucher['scope']);
        self::assertSame('20.00', $voucher['value']);

        $this->client->jsonRequest('PATCH', '/api/admin/vouchers/'.$voucher['id'], ['is_active' => false, 'value' => 15], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['voucher']['is_active']);
        self::assertSame('15.00', $this->payload()['voucher']['value']);

        $this->client->request('DELETE', '/api/admin/vouchers/'.$voucher['id'], server: $this->auth($token));
        self::assertResponseStatusCodeSame(204);
    }

    public function testSupportTicketStatusAndAuditLogVisibility(): void
    {
        $token = $this->login();
        $this->client->request('GET', '/api/admin/support-tickets?status=open', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame($this->ticketId, (string) ($this->payload()['data'][0]['id'] ?? ''));
        self::assertFalse($this->payload()['data'][0]['reply_capability']);

        $this->client->jsonRequest('PATCH', '/api/admin/support-tickets/'.$this->ticketId, ['status' => 'in_progress'], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('in_progress', $this->payload()['ticket']['status']);

        $this->client->request('GET', '/api/admin/audit-logs?action=support_ticket.status_updated', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('support_ticket.status_updated', $this->payload()['data'][0]['action']);
    }

    private function createFixture(): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Content Admin', self::ADMIN_EMAIL, '+639190500001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Support Customer', self::CUSTOMER_EMAIL, '+639190500002', UserRole::CUSTOMER, $now);
        $ticket = (new SupportTicket())->setUser($customerUser)->setSubject('Test support ticket')->setMessage('This is a single-message ticket.')
            ->setStatus(SupportTicketStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $customerUser, $ticket] as $entity) { $entityManager->persist($entity); }
        $entityManager->flush();
        return (string) $ticket->getId();
    }

    private function user(string $name, string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)->setStatus(UserStatus::ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
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
        $connection->executeStatement("DELETE FROM audit_logs WHERE action LIKE 'voucher.%' OR action LIKE 'support_ticket.%'");
        $connection->executeStatement("DELETE FROM vouchers WHERE code = 'ADMINTEST20'");
        $connection->executeStatement("DELETE FROM support_tickets WHERE subject = 'Test support ticket'");
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::ADMIN_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?)', [self::ADMIN_EMAIL, self::CUSTOMER_EMAIL]);
    }
}
