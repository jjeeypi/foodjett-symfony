<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Enum\SupportTicketStatus;
use App\Enum\UserStatus;

final class CustomerAccountControllerTest extends CustomerApiTestCase
{
    public function testProfileCanBeReadAndUpdatedAndEmailChangeResetsVerification(): void
    {
        $this->customerUser->setEmailVerifiedAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();
        $newEmail = strtolower($this->marker).'@updated.foodjett.test';

        $this->client->request('GET', '/api/customer/profile', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame($this->customerUser->getId(), $this->payload()['profile']['id']);

        $this->client->jsonRequest('PATCH', '/api/customer/profile', [
            'name' => 'Updated Customer', 'email' => $newEmail, 'phone' => '+639170001234',
        ], server: $this->auth());
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertTrue($payload['email_verification_reset']);
        self::assertSame('Updated Customer', $payload['profile']['name']);
        self::assertSame($newEmail, $payload['profile']['email']);
        self::assertNull($payload['profile']['email_verified_at']);

        $this->entityManager->clear();
        $updated = $this->entityManager->find(User::class, $this->customerUser->getId());
        self::assertInstanceOf(User::class, $updated);
        self::assertSame('+639170001234', $updated->getPhone());
        self::assertNull($updated->getEmailVerifiedAt());
    }

    public function testProfileRejectsAnotherUsersPhoneNumber(): void
    {
        $this->client->jsonRequest('PATCH', '/api/customer/profile', [
            'name' => $this->customerUser->getName(), 'email' => $this->customerUser->getEmail(), 'phone' => $this->otherCustomerUser->getPhone(),
        ], server: $this->auth());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('phone', $this->payload()['errors']);
    }

    public function testSupportTicketsCanBeCreatedAndOnlyOwnTicketsAreListed(): void
    {
        $now = new \DateTimeImmutable('-1 hour');
        $foreign = (new SupportTicket())->setUser($this->otherCustomerUser)->setSubject('Foreign ticket')->setMessage('Not visible')
            ->setStatus(SupportTicketStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($foreign);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/customer/support-tickets', ['subject' => 'Account question', 'message' => 'Please help with my account.'], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('open', $this->payload()['ticket']['status']);

        $this->client->request('GET', '/api/customer/support-tickets', server: $this->auth());
        self::assertResponseIsSuccessful();
        $list = $this->payload();
        self::assertSame(1, $list['meta']['total']);
        self::assertSame('Account question', $list['data'][0]['subject']);
        self::assertNotSame($foreign->getId(), $list['data'][0]['id']);
    }

    public function testAccountDeletionAnonymizesButPreservesCustomerAndOrderHistory(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $userId = (string) $this->customerUser->getId();
        $customerId = (string) $this->customer->getId();
        $this->entityManager->flush();
        $orderId = (string) $order->getId();

        $this->client->request('DELETE', '/api/customer/account', server: $this->auth());
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Deleted Customer', $user->getName());
        self::assertNull($user->getEmail());
        self::assertNull($user->getPhone());
        self::assertNull($user->getAvatarPath());
        self::assertSame(UserStatus::BANNED, $user->getStatus());
        self::assertNotNull($this->entityManager->find(Customer::class, $customerId));
        self::assertNotNull($this->entityManager->find(\App\Entity\Order::class, $orderId));
    }
}
