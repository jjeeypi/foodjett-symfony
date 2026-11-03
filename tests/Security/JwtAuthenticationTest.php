<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JwtAuthenticationTest extends WebTestCase
{
    private const ACTIVE_EMAIL = 'jwt-active@foodjett.test';
    private const SUSPENDED_EMAIL = 'jwt-suspended@foodjett.test';
    private const PASSWORD = 'TestPassword123!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->deleteTestUsers($entityManager);
        $this->createUser($entityManager, self::ACTIVE_EMAIL, UserStatus::ACTIVE);
        $this->createUser($entityManager, self::SUSPENDED_EMAIL, UserStatus::SUSPENDED);
    }

    protected function tearDown(): void
    {
        if (self::$kernel?->getContainer()->has(EntityManagerInterface::class)) {
            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $this->deleteTestUsers($entityManager);
            $entityManager->close();
        }

        parent::tearDown();
    }

    public function testActiveUserCanLoginByEmailAndUseJwt(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'login' => self::ACTIVE_EMAIL,
            'password' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($payload['token'] ?? null);
        self::assertNotSame('', $payload['token']);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$payload['token'],
        ]);

        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::ACTIVE_EMAIL, $me['email'] ?? null);
        self::assertSame(UserRole::CUSTOMER->value, $me['role'] ?? null);
        self::assertSame(UserStatus::ACTIVE->value, $me['status'] ?? null);
    }

    public function testActiveUserCanLoginByPhone(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'login' => '+639171234567',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
    }

    public function testProtectedEndpointRejectsMissingJwt(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSuspendedUserCannotReceiveJwt(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'login' => self::SUSPENDED_EMAIL,
            'password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            'Your account is suspended. Contact support for assistance.',
            $payload['message'] ?? null,
        );
    }

    private function createUser(EntityManagerInterface $entityManager, string $email, UserStatus $status): void
    {
        $now = new \DateTimeImmutable();
        $phone = UserStatus::ACTIVE === $status ? '+639171234567' : '+639171234568';

        $user = (new User())
            ->setName('JWT Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole(UserRole::CUSTOMER)
            ->setStatus($status)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $entityManager->persist($user);
        $entityManager->flush();
    }

    private function deleteTestUsers(EntityManagerInterface $entityManager): void
    {
        $entityManager->createQueryBuilder()
            ->delete(User::class, 'u')
            ->where('u.email IN (:emails)')
            ->setParameter('emails', [self::ACTIVE_EMAIL, self::SUSPENDED_EMAIL])
            ->getQuery()
            ->execute();
    }
}
