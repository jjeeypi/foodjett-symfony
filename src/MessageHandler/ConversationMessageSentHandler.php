<?php

namespace App\MessageHandler;

use App\Entity\Message;
use App\Entity\User;
use App\Message\ConversationMessageSent;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ConversationMessageSentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HubInterface $hub,
        private readonly MessageRepository $messages,
    ) {
    }

    public function __invoke(ConversationMessageSent $message): void
    {
        $sent = $this->entityManager->find(Message::class, $message->messageId);
        $recipient = $this->entityManager->find(User::class, $message->recipientUserId);
        if (!$sent instanceof Message || !$recipient instanceof User) {
            return;
        }

        $this->hub->publish(new Update(
            sprintf('conversations/%s', $message->conversationId),
            json_encode([
                'event' => 'message.sent',
                'message_id' => $message->messageId,
                'conversation_id' => $message->conversationId,
                'sender' => [
                    'id' => $message->senderUserId,
                    'name' => $sent->getSender()->getName(),
                ],
                'body' => $sent->getBody(),
                'created_at' => $message->sentAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
            true,
            type: 'message.sent',
        ));

        $this->hub->publish(new Update(
            sprintf('users/%s/notifications', $message->recipientUserId),
            json_encode([
                'event' => 'notifications.unread_count_updated',
                'user_id' => $message->recipientUserId,
                'conversation_id' => $message->conversationId,
                'unread_count' => $this->messages->countUnreadFor($recipient),
            ], JSON_THROW_ON_ERROR),
            true,
            type: 'notifications.unread_count_updated',
        ));
    }
}
