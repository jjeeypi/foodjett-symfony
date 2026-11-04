<?php

namespace App\Tests\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Order;
use App\Entity\User;
use App\Tests\Controller\Api\CustomerApiTestCase;
use App\Tests\Double\RecordingMercureHub;

/** Order chats as pages, for the customer, the restaurant and the rider. */
class ConversationPagesTest extends CustomerApiTestCase
{
    public function testListAndMessagesAreScopedAndInOrder(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $foreignOrder = $this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress);
        $conversation = $this->conversation($order, new \DateTimeImmutable('-1 hour'));
        $foreign = $this->conversation($foreignOrder, new \DateTimeImmutable());
        $this->persist($conversation, $foreign,
            $this->message($conversation, $this->restaurant->getUser(), 'First update', new \DateTimeImmutable('-50 minutes')),
            $this->message($conversation, $this->customerUser, 'Thank you', new \DateTimeImmutable('-40 minutes')));
        $this->entityManager->flush();
        $this->client->loginUser($this->customerUser, 'main');

        $crawler = $this->page('/messages');
        self::assertSame([$order->getOrderNumber()], $crawler->filter('tbody td:first-child a')->each(fn ($a) => $a->text()));
        self::assertSame('1', $crawler->filter('tbody td:nth-child(3)')->text(), 'One unread message from the restaurant.');

        $crawler = $this->page('/messages/'.$conversation->getId());
        self::assertSame(['First update', 'Thank you'], $crawler->filter('#messages .body')->each(fn ($body) => $body->text()));
    }

    public function testSendingPublishesAndOpeningMarksTheOtherSideRead(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $conversation = $this->conversation($order, new \DateTimeImmutable('-5 minutes'));
        $incoming = $this->message($conversation, $this->restaurant->getUser(), 'Your food is ready.', new \DateTimeImmutable('-2 minutes'));
        $outgoing = $this->message($conversation, $this->customerUser, 'On my way.', new \DateTimeImmutable('-1 minute'));
        $this->persist($conversation, $incoming, $outgoing);
        $this->entityManager->flush();
        $hub = self::getContainer()->get(RecordingMercureHub::class);
        $hub->reset();
        $this->client->loginUser($this->customerUser, 'main');

        $crawler = $this->page('/messages/'.$conversation->getId());
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Message::class, $incoming->getId())->getReadAt(), 'Opening the chat reads incoming messages.');
        self::assertNull($this->entityManager->find(Message::class, $outgoing->getId())->getReadAt(), 'Own messages stay unread for the restaurant.');

        $this->client->submit($crawler->selectButton('Send')->form(['message_form[body]' => 'Thanks for the update.']));
        self::assertResponseRedirects('/messages/'.$conversation->getId());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#messages', 'Thanks for the update.');

        $updates = $hub->updates();
        self::assertCount(2, $updates);
        self::assertSame(['conversations/'.$conversation->getId()], $updates[0]->getTopics(), 'The open chat refreshes.');
        self::assertSame(['users/'.$this->restaurant->getUser()->getId().'/notifications'], $updates[1]->getTopics(), "The recipient's inbox refreshes.");
        self::assertSame('<turbo-stream action="refresh"></turbo-stream>', $updates[1]->getData());
        self::assertTrue($updates[1]->isPrivate());

        // The restaurant owner can read and reply in the same chat.
        $this->client->loginUser($this->fresh(User::class, (string) $this->restaurant->getUser()->getId()), 'main');
        $crawler = $this->page('/messages/'.$conversation->getId());
        $this->client->submit($crawler->selectButton('Send')->form(['message_form[body]' => 'See you soon.']));
        self::assertResponseRedirects();
    }

    public function testOthersCannotOpenAChat(): void
    {
        $foreign = $this->conversation($this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress), new \DateTimeImmutable());
        $this->persist($foreign);
        $this->entityManager->flush();
        $this->client->loginUser($this->customerUser, 'main');

        $this->client->request('GET', '/messages/'.$foreign->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testClosedChatsAreReadOnlyAndSendingIsRateLimited(): void
    {
        $closed = $this->conversation($this->createOrder($this->customer, $this->restaurant, $this->address), new \DateTimeImmutable('-1 hour'))
            ->setClosedAt(new \DateTimeImmutable('-1 minute'));
        $open = $this->conversation($this->createOrder($this->customer, $this->otherRestaurant, $this->address), new \DateTimeImmutable());
        $this->persist($closed, $open);
        for ($index = 0; $index < 20; ++$index) {
            $this->persist($this->message($open, $this->customerUser, 'Message '.$index, new \DateTimeImmutable('-10 seconds')));
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->customerUser, 'main');

        $this->page('/messages/'.$closed->getId());
        self::assertSelectorTextContains('main', 'This conversation is read-only.');
        self::assertSelectorNotExists('form[name="message_form"]');

        $crawler = $this->page('/messages/'.$open->getId());
        $this->client->submit($crawler->selectButton('Send')->form(['message_form[body]' => 'One too many']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Too many messages');
        self::assertSelectorTextNotContains('#messages', 'One too many');
    }

    public function testCustomerOpensTheRiderChatOnceAndOnlyWhenARiderIsAssigned(): void
    {
        $rider = $this->createRider();
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_RIDER_ASSIGNED)
            ->setRider($rider)->setAcceptedAt(new \DateTimeImmutable('-10 minutes'));
        $unassigned = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $this->entityManager->flush();
        $this->client->loginUser($this->customerUser, 'main');

        $this->startChat($order, 'Message the rider');
        self::assertResponseRedirects();
        $first = $this->client->getResponse()->headers->get('Location');
        $this->startChat($order, 'Message the rider');
        self::assertSame($first, $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->entityManager->getRepository(Conversation::class)->count([
            'order' => $order->getId(), 'type' => Conversation::TYPE_CUSTOMER_RIDER,
        ]));

        $crawler = $this->page('/orders/'.$unassigned->getId());
        self::assertCount(0, $crawler->filter('button:contains("Message the rider")'));
        self::assertCount(0, $crawler->filter('button:contains("Message the restaurant")'), 'Restaurant chat opens after acceptance.');

        // The assigned rider sees and answers the chat.
        $this->client->loginUser($this->fresh(User::class, (string) $rider->getUser()->getId()), 'main');
        $crawler = $this->page('/messages');
        self::assertSame([$order->getOrderNumber()], $crawler->filter('tbody td:first-child a')->each(fn ($a) => $a->text()));
    }

    private function startChat(Order $order, string $button): void
    {
        $crawler = $this->page('/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton($button)->form());
    }

    private function page(string $url): \Symfony\Component\DomCrawler\Crawler
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function conversation(Order $order, \DateTimeImmutable $at): Conversation
    {
        return (new Conversation())->setOrder($order)->setType(Conversation::TYPE_CUSTOMER_RESTAURANT)->setCreatedAt($at)->setUpdatedAt($at);
    }

    private function message(Conversation $conversation, User $sender, string $body, \DateTimeImmutable $at): Message
    {
        $message = (new Message())->setConversation($conversation)->setSender($sender)->setBody($body)->setCreatedAt($at)->setUpdatedAt($at);
        $conversation->addMessage($message);

        return $message;
    }
}
