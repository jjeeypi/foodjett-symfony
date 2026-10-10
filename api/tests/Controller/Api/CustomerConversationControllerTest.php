<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Enum\ConversationType;
use App\Tests\Double\RecordingMercureHub;

final class CustomerConversationControllerTest extends CustomerApiTestCase
{
    public function testConversationListAndMessagesAreScopedAndOrdered(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $foreignOrder = $this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress);
        $conversation = $this->conversation($order, new \DateTimeImmutable('-1 hour'));
        $foreign = $this->conversation($foreignOrder, new \DateTimeImmutable());
        $older = $this->message($conversation, $this->restaurant->getUser(), 'First update', new \DateTimeImmutable('-50 minutes'));
        $newer = $this->message($conversation, $this->customerUser, 'Thank you', new \DateTimeImmutable('-40 minutes'));
        $this->persist($conversation, $foreign, $older, $newer);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/customer/conversations', server: $this->auth());
        self::assertResponseIsSuccessful();
        $list = $this->payload();
        self::assertSame(1, $list['meta']['total']);
        self::assertSame($conversation->getId(), $list['data'][0]['id']);
        self::assertSame(1, $list['data'][0]['unread_count']);
        self::assertSame('Thank you', $list['data'][0]['last_message']['body']);

        $this->client->request('GET', '/api/customer/conversations/'.$conversation->getId().'/messages', server: $this->auth());
        self::assertResponseIsSuccessful();
        $messages = $this->payload()['data'];
        self::assertSame(['First update', 'Thank you'], array_column($messages, 'body'));

        $this->client->request('GET', '/api/customer/conversations/'.$foreign->getId().'/messages', server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCustomerCanSendAndMarkCounterpartMessagesRead(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $conversation = $this->conversation($order, new \DateTimeImmutable('-5 minutes'));
        $incoming = $this->message($conversation, $this->restaurant->getUser(), 'Your food is ready.', new \DateTimeImmutable('-2 minutes'));
        $outgoing = $this->message($conversation, $this->customerUser, 'On my way.', new \DateTimeImmutable('-1 minute'));
        $this->persist($conversation, $incoming, $outgoing);
        $this->entityManager->flush();
        $incomingId = (string) $incoming->getId();
        $outgoingId = (string) $outgoing->getId();
        $hub = self::getContainer()->get(RecordingMercureHub::class);
        $hub->reset();

        $this->client->jsonRequest('POST', '/api/customer/conversations/'.$conversation->getId().'/messages', ['body' => 'Thanks for the update.'], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Thanks for the update.', $this->payload()['message']['body']);
        self::assertCount(2, $hub->updates());
        self::assertSame(['conversations/'.$conversation->getId()], $hub->updates()[0]->getTopics());
        self::assertTrue($hub->updates()[0]->isPrivate());
        $messagePayload = json_decode($hub->updates()[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('message.sent', $messagePayload['event'] ?? null);
        self::assertSame('Thanks for the update.', $messagePayload['body'] ?? null);
        self::assertSame(['users/'.$this->restaurant->getUser()->getId().'/notifications'], $hub->updates()[1]->getTopics());
        $notificationPayload = json_decode($hub->updates()[1]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('notifications.unread_count_updated', $notificationPayload['event'] ?? null);
        self::assertSame(2, $notificationPayload['unread_count'] ?? null);

        $this->client->request('POST', '/api/customer/conversations/'.$conversation->getId().'/mark-read', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['updated']);
        $this->entityManager->clear();
        $incoming = $this->entityManager->find(Message::class, $incomingId);
        $outgoing = $this->entityManager->find(Message::class, $outgoingId);
        self::assertInstanceOf(Message::class, $incoming);
        self::assertInstanceOf(Message::class, $outgoing);
        self::assertNotNull($incoming->getReadAt());
        self::assertNull($outgoing->getReadAt());
    }

    public function testClosedConversationAndMessageRateLimitAreEnforced(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $closed = $this->conversation($order, new \DateTimeImmutable('-1 hour'))->setClosedAt(new \DateTimeImmutable('-1 minute'));
        $secondOrder = $this->createOrder($this->customer, $this->otherRestaurant, $this->address);
        $open = $this->conversation($secondOrder, new \DateTimeImmutable());
        $this->persist($closed, $open);
        for ($index = 0; $index < 20; ++$index) {
            $this->persist($this->message($open, $this->customerUser, 'Message '.$index, new \DateTimeImmutable('-10 seconds')));
        }
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/customer/conversations/'.$closed->getId().'/messages', ['body' => 'Too late'], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('read-only', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/customer/conversations/'.$open->getId().'/messages', ['body' => 'One too many'], server: $this->auth());
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Too many messages', $this->payload()['message']);
    }

    private function conversation(\App\Entity\Order $order, \DateTimeImmutable $at): Conversation
    {
        return (new Conversation())->setOrder($order)->setType(ConversationType::CUSTOMER_RESTAURANT)->setCreatedAt($at)->setUpdatedAt($at);
    }

    private function message(Conversation $conversation, \App\Entity\User $sender, string $body, \DateTimeImmutable $at): Message
    {
        $message = (new Message())->setConversation($conversation)->setSender($sender)->setBody($body)->setCreatedAt($at)->setUpdatedAt($at);
        $conversation->addMessage($message);

        return $message;
    }
}
