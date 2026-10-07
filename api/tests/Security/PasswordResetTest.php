<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordResetTest extends WebTestCase
{
    private const EMAIL = 'password-reset@foodjett.test';
    private const OLD_PASSWORD = 'OldPassword123!';
    private const NEW_PASSWORD = 'NewPassword456!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->deleteUser();
        $now = new \DateTimeImmutable();
        $user = (new User())
            ->setName('Password Reset Test')
            ->setEmail(self::EMAIL)
            ->setPhone('+639186666666')
            ->setPassword(password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT))
            ->setRole(UserRole::CUSTOMER)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (self::$kernel?->getContainer()->has(EntityManagerInterface::class)) {
            $this->deleteUser();
            self::getContainer()->get(EntityManagerInterface::class)->close();
        }
        parent::tearDown();
    }

    public function testPasswordCanBeResetWithDevelopmentToken(): void
    {
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => self::EMAIL]);
        self::assertResponseStatusCodeSame(202);
        $payload = $this->payload();
        self::assertSame('development_log_stub', $payload['delivery'] ?? null);
        self::assertNotEmpty($payload['reset_token'] ?? null);

        $this->client->jsonRequest('POST', '/api/reset-password', [
            'token' => $payload['reset_token'],
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/login', ['login' => self::EMAIL, 'password' => self::OLD_PASSWORD]);
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('POST', '/api/login', ['login' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    public function testForgotPasswordDoesNotRevealUnknownEmail(): void
    {
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'unknown@foodjett.test']);
        self::assertResponseStatusCodeSame(202);
        $payload = $this->payload();
        self::assertArrayNotHasKey('reset_token', $payload);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function deleteUser(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if ($user instanceof User) {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }
}
