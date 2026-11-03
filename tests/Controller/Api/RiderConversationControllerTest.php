<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Enum\ConversationType;
use App\Enum\OrderStatus;
use App\Tests\Double\RecordingMercureHub;

final class RiderConversationControllerTest extends RiderApiTestCase
{
    public function testConversationListAndMessagesAreScopedToAssignedRider(): void
    {
        $order = $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->rider);
        $foreignOrder = $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->otherRider);
        $conversation = $this->conversation($order, ConversationType::CUSTOMER_RIDER, new \DateTimeImmutable('-1 hour'));
        $restaurantConversation = $this->conversation($order, ConversationType::CUSTOMER_RESTAURANT, new \DateTimeImmutable());
        $foreign = $this->conversation($foreignOrder, ConversationType::CUSTOMER_RIDER, new \DateTimeImmutable());
        $older = $this->message($conversation, $this->customer->getUser(), 'Please call when you arrive.', new \DateTimeImmutable('-50 minutes'));
        $newer = $this->message($conversation, $this->riderUser, 'I am on my way.', new \DateTimeImmutable('-40 minutes'));
        $this->persist($conversation, $restaurantConversation, $foreign, $older, $newer);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/rider/conversations', server: $this->auth());
        self::assertResponseIsSuccessful();
        $list = $this->payload();
        self::assertSame(1, $list['meta']['total']);
        self::assertSame($conversation->getId(), $list['data'][0]['id']);
        self::assertSame('customer', $list['data'][0]['counterpart']['type']);
        self::assertSame(1, $list['data'][0]['unread_count']);
        self::assertSame('I am on my way.', $list['data'][0]['last_message']['body']);

        $this->client->request('GET', '/api/rider/conversations/'.$conversation->getId().'/messages', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Please call when you arrive.', 'I am on my way.'],
            array_column($this->payload()['data'], 'body'),
        );

        $this->client->request('GET', '/api/rider/conversations/'.$foreign->getId().'/messages', server: $this->auth());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/rider/conversations/'.$restaurantConversation->getId().'/messages', server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRiderCanSendAndMarkCustomerMessagesRead(): void
    {
        $order = $this->createOrder(OrderStatus::ON_THE_WAY, $this->rider);
        $conversation = $this->conversation($order, ConversationType::CUSTOMER_RIDER, new \DateTimeImmutable('-5 minutes'));
        $incoming = $this->message($conversation, $this->customer->getUser(), 'The gate is blue.', new \DateTimeImmutable('-2 minutes'));
        $outgoing = $this->message($conversation, $this->riderUser, 'Understood.', new \DateTimeImmutable('-1 minute'));
        $this->persist($conversation, $incoming, $outgoing);
        $this->entityManager->flush();
        $incomingId = (string) $incoming->getId();
        $outgoingId = (string) $outgoing->getId();
        $hub = self::getContainer()->get(RecordingMercureHub::class);
        $hub->reset();

        $this->client->jsonRequest('POST', '/api/rider/conversations/'.$conversation->getId().'/messages', ['body' => 'I have arrived nearby.'], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('I have arrived nearby.', $this->payload()['message']['body']);
        self::assertCount(2, $hub->updates());
        self::assertSame(['conversations/'.$conversation->getId()], $hub->updates()[0]->getTopics());
        self::assertTrue($hub->updates()[0]->isPrivate());
        $messagePayload = json_decode($hub->updates()[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('message.sent', $messagePayload['event'] ?? null);
        self::assertSame('I have arrived nearby.', $messagePayload['body'] ?? null);
        self::assertSame(['users/'.$this->customer->getUser()->getId().'/notifications'], $hub->updates()[1]->getTopics());
        $notificationPayload = json_decode($hub->updates()[1]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('notifications.unread_count_updated', $notificationPayload['event'] ?? null);
        self::assertSame(2, $notificationPayload['unread_count'] ?? null);

        $this->client->request('POST', '/api/rider/conversations/'.$conversation->getId().'/mark-read', server: $this->auth());
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
        $closedOrder = $this->createOrder(OrderStatus::DELIVERED, $this->rider);
        $closed = $this->conversation($closedOrder, ConversationType::CUSTOMER_RIDER, new \DateTimeImmutable('-1 hour'))
            ->setClosedAt(new \DateTimeImmutable('-1 minute'));
        $openOrder = $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->rider);
        $open = $this->conversation($openOrder, ConversationType::CUSTOMER_RIDER, new \DateTimeImmutable());
        $this->persist($closed, $open);
        for ($index = 0; $index < 20; ++$index) {
            $this->persist($this->message($open, $this->riderUser, 'Message '.$index, new \DateTimeImmutable('-10 seconds')));
        }
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/rider/conversations/'.$closed->getId().'/messages', ['body' => 'Too late'], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('read-only', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/rider/conversations/'.$open->getId().'/messages', ['body' => 'One too many'], server: $this->auth());
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Too many messages', $this->payload()['message']);
    }

    private function conversation(\App\Entity\Order $order, ConversationType $type, \DateTimeImmutable $at): Conversation
    {
        return (new Conversation())->setOrder($order)->setType($type)->setCreatedAt($at)->setUpdatedAt($at);
    }

    private function message(Conversation $conversation, \App\Entity\User $sender, string $body, \DateTimeImmutable $at): Message
    {
        $message = (new Message())->setConversation($conversation)->setSender($sender)->setBody($body)->setCreatedAt($at)->setUpdatedAt($at);
        $conversation->addMessage($message);

        return $message;
    }
}
