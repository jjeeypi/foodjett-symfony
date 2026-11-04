<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Message;
use App\Entity\User;
use App\Event\MessageSentEvent;
use App\Repository\MessageRepository;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class MessageRealtimeListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MercurePublisher $publisher,
        private MessageRepository $messages,
    ) {
    }

    #[AsEventListener]
    public function __invoke(MessageSentEvent $event): void
    {
        $message = $this->entityManager->find(Message::class, $event->messageId);
        $recipient = $this->entityManager->find(User::class, $event->recipientUserId);
        if (!$message instanceof Message || !$recipient instanceof User) {
            return;
        }

        $this->publisher->publish(sprintf('conversations/%s', $event->conversationId), [
            'event' => 'message.sent',
            'message_id' => $event->messageId,
            'conversation_id' => $event->conversationId,
            'sender' => [
                'id' => $event->senderUserId,
                'name' => $message->getSender()->getName(),
            ],
            'body' => $message->getBody(),
            'created_at' => $event->sentAt->format(\DateTimeInterface::ATOM),
        ], type: 'message.sent');

        $this->publisher->publish(sprintf('users/%s/notifications', $event->recipientUserId), [
            'event' => 'notifications.unread_count_updated',
            'user_id' => $event->recipientUserId,
            'conversation_id' => $event->conversationId,
            'unread_count' => $this->messages->countUnreadFor($recipient),
        ], type: 'notifications.unread_count_updated');
    }
}
