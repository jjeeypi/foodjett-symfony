<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Message;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Enum\UserRole;
use App\Event\MessageSentEvent;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class MessageRealtimeListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MercurePublisher $publisher,
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
            'unread_count' => $this->unreadCount($recipient),
        ], type: 'notifications.unread_count_updated');
    }

    private function unreadCount(User $recipient): int
    {
        $builder = $this->entityManager->getRepository(Message::class)->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->innerJoin('message.conversation', 'conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('message.readAt IS NULL')
            ->andWhere('message.sender != :recipient')->setParameter('recipient', $recipient);

        match ($recipient->getRole()) {
            UserRole::CUSTOMER => $builder->andWhere('orders.customer = :profile')->setParameter('profile', $recipient->getCustomer()),
            UserRole::RIDER => $builder->andWhere('orders.rider = :profile')->setParameter('profile', $recipient->getRider())
                ->andWhere('conversation.type = :conversationType')->setParameter('conversationType', ConversationType::CUSTOMER_RIDER),
            UserRole::RESTAURANT => $builder->andWhere('orders.restaurant = :profile')->setParameter('profile', $recipient->getRestaurant())
                ->andWhere('conversation.type = :conversationType')->setParameter('conversationType', ConversationType::CUSTOMER_RESTAURANT),
            UserRole::ADMIN => $builder->andWhere('1 = 0'),
        };

        return (int) $builder->getQuery()->getSingleScalarResult();
    }
}
