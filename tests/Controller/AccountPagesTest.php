<?php

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Tests\Controller\Api\CustomerApiTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** The customer's profile page, support requests and account deletion. */
class AccountPagesTest extends CustomerApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->customerUser, 'main');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.public_upload_dir'));
        parent::tearDown();
    }

    public function testProfileUpdateResetsEmailVerificationAndStoresThePhoto(): void
    {
        $this->fresh(User::class, (string) $this->customerUser->getId())->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $photo = sys_get_temp_dir().'/foodjett-avatar.png';
        // a 1×1 transparent PNG
        file_put_contents($photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $crawler = $this->client->request('GET', '/account');
        $form = $crawler->selectButton('Save profile')->form([
            'profile_form[name]' => 'Renamed Customer',
            'profile_form[email]' => 'Renamed.'.$this->marker.'@Foodjett.test',
        ]);
        $form['profile_form[avatar]']->upload($photo);
        $this->client->submit($form);
        self::assertResponseRedirects('/account');

        $user = $this->fresh(User::class, (string) $this->customerUser->getId());
        self::assertSame('Renamed Customer', $user->getName());
        self::assertSame(strtolower('renamed.'.$this->marker.'@foodjett.test'), $user->getEmail());
        self::assertNull($user->getEmailVerifiedAt());
        self::assertStringStartsWith('uploads/customers/avatars/', (string) $user->getAvatarPath());
        self::assertFileExists(self::getContainer()->getParameter('app.public_upload_dir').'/'.substr($user->getAvatarPath(), 8));

        // Still signed in after changing the email.
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        @unlink($photo);
    }

    public function testProfileRefusesAnotherAccountsPhoneNumber(): void
    {
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->selectButton('Save profile')->form([
            'profile_form[phone]' => $this->otherCustomerUser->getPhone(),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This phone number is already registered.');
    }

    public function testSupportRequestsAreCreatedAndOnlyOwnOnesAreListed(): void
    {
        $now = new \DateTimeImmutable();
        $foreign = (new SupportTicket())->setUser($this->fresh(User::class, (string) $this->otherCustomerUser->getId()))
            ->setSubject('Not yours')->setMessage('Other customer')->setStatus(SupportTicket::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($foreign);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/support/new');
        $this->client->submit($crawler->selectButton('Send')->form([
            'support_ticket[subject]' => 'Missing refund',
            'support_ticket[message]' => 'My refund has not arrived.',
        ]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Missing refund');

        $crawler = $this->client->request('GET', '/support');
        self::assertSame(['Missing refund'], $crawler->filter('tbody td a')->each(fn ($a) => $a->text()));
        $this->client->request('GET', '/support/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/support/new');
        $this->client->submit($crawler->selectButton('Send')->form(['support_ticket[subject]' => '']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletingTheAccountAnonymizesItSignsOutAndKeepsOrders(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $this->entityManager->flush();
        $ids = ['user' => (string) $this->customerUser->getId(), 'customer' => (string) $this->customer->getId(), 'order' => (string) $order->getId()];

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->selectButton('Delete my account')->form());
        self::assertResponseRedirects('/');

        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $ids['user']);
        self::assertSame('Deleted Customer', $user->getName());
        self::assertNull($user->getEmail());
        self::assertNull($user->getPhone());
        self::assertSame(User::STATUS_BANNED, $user->getStatus());
        self::assertNotNull($this->entityManager->find(Customer::class, $ids['customer']));
        self::assertNotNull($this->entityManager->find(Order::class, $ids['order']));

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }
}
