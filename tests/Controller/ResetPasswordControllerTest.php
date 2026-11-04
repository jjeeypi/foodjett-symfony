<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

class ResetPasswordControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPasswordIsResetThroughTheEmailedLink(): void
    {
        $this->createUser('reset-me@foodjett.test', 'OldPassword123!');

        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Send password reset email')->form([
            'reset_password_request_form[email]' => 'Reset-Me@Foodjett.test',
        ]));
        self::assertResponseRedirects('/reset-password/check-email');

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'reset-me@foodjett.test');
        self::assertSame(1, preg_match('#/reset-password/reset/[A-Za-z0-9]+#', (string) $email->getHtmlBody(), $link));

        $this->client->request('GET', $link[0]);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Reset password')->form([
            'change_password_form[plainPassword][first]' => 'NewPassword123!',
            'change_password_form[plainPassword][second]' => 'NewPassword123!',
        ]));
        self::assertResponseRedirects('/login');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your password was changed.');
        $this->client->submit($crawler->selectButton('Log in')->form([
            '_username' => 'reset-me@foodjett.test',
            '_password' => 'NewPassword123!',
        ]));
        self::assertResponseRedirects('/');
    }

    public function testUnknownEmailLooksTheSameAndSendsNothing(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Send password reset email')->form([
            'reset_password_request_form[email]' => 'nobody@foodjett.test',
        ]));

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertNull(self::getMailerMessage());
    }

    public function testResetLinkWithAnUnknownTokenIsRejected(): void
    {
        $this->client->request('GET', '/reset-password/reset/not-a-real-token');
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
    }

    private function createUser(string $email, string $password): void
    {
        $now = new \DateTimeImmutable();
        $user = (new User())
            ->setName('Reset Test User')
            ->setEmail($email)
            ->setPhone('+639190000001')
            ->setPassword(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole(User::ACCOUNT_CUSTOMER)
            ->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
    }
}
