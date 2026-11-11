<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Attempts are counted in the cache; start every test without earlier ones.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testLoginPageRenders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Log in');
        self::assertSelectorExists('.password-field[data-controller="password-visibility"] #password[type="password"]');
        self::assertSelectorExists('.password-field button.password-toggle[type="button"][aria-label="Show password"]');
    }

    public function testCustomerLogsInWithEmailAndLogsOut(): void
    {
        $this->createUser('web-login@foodjett.test', '+639170000001');

        $this->login('web-login@foodjett.test');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.profile', 'Signed in as Web Test User');

        $this->client->request('GET', '/logout');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Log in');
    }

    public function testCustomerLogsInWithPhoneNumber(): void
    {
        $this->createUser('web-phone@foodjett.test', '+639170000002');

        $this->login('+639170000002');
        self::assertResponseRedirects('/');
    }

    public function testWrongPasswordShowsAnError(): void
    {
        $this->createUser('web-wrong@foodjett.test', '+639170000003');

        $this->login('web-wrong@foodjett.test', 'not-the-password');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'Invalid credentials.');
    }

    public function testSuspendedAccountCannotLogIn(): void
    {
        $this->createUser('web-suspended@foodjett.test', '+639170000004', User::STATUS_SUSPENDED);

        $this->login('web-suspended@foodjett.test');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'Your account is suspended.');
    }

    public function testOpenSessionEndsWhenTheAccountIsSuspended(): void
    {
        $this->createUser('web-session@foodjett.test', '+639170000005');
        $this->login('web-session@foodjett.test');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.profile', 'Signed in as');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'web-session@foodjett.test']);
        $user->setStatus(User::STATUS_SUSPENDED);
        $entityManager->flush();

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.profile');
    }

    public function testLoginIsThrottledAfterFiveFailedAttempts(): void
    {
        $this->createUser('web-throttle@foodjett.test', '+639170000006');
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('web-throttle@foodjett.test', 'not-the-password');
        }
        $this->login('web-throttle@foodjett.test');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.error', 'Too many failed login attempts');
    }

    private function login(string $username, string $password = self::PASSWORD): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Log in')->form([
            '_username' => $username,
            '_password' => $password,
        ]));
    }

    private function createUser(string $email, string $phone, string $status = User::STATUS_ACTIVE): void
    {
        $now = new \DateTimeImmutable();
        $user = (new User())
            ->setName('Web Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole(User::ACCOUNT_CUSTOMER)
            ->setStatus($status)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
    }
}
