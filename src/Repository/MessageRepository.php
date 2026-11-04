<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Counts unread messages addressed to this user across the order conversations they take part in.
     */
    public function countUnreadFor(User $recipient): int
    {
        $builder = $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->innerJoin('message.conversation', 'conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('message.readAt IS NULL')
            ->andWhere('message.sender != :recipient')->setParameter('recipient', $recipient);

        match ($recipient->getRole()) {
            User::ACCOUNT_CUSTOMER => $builder->andWhere('orders.customer = :profile')->setParameter('profile', $recipient->getCustomer()),
            User::ACCOUNT_RIDER => $builder->andWhere('orders.rider = :profile')->setParameter('profile', $recipient->getRider())
                ->andWhere('conversation.type = :conversationType')->setParameter('conversationType', Conversation::TYPE_CUSTOMER_RIDER),
            User::ACCOUNT_RESTAURANT => $builder->andWhere('orders.restaurant = :profile')->setParameter('profile', $recipient->getRestaurant())
                ->andWhere('conversation.type = :conversationType')->setParameter('conversationType', Conversation::TYPE_CUSTOMER_RESTAURANT),
            User::ACCOUNT_ADMIN => $builder->andWhere('1 = 0'),
        };

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /** Messages of a conversation in the order they were sent. */
    public function createForConversationQuery(Conversation $conversation): QueryBuilder
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->orderBy('message.createdAt', 'ASC')->addOrderBy('message.id', 'ASC');
    }

    /** How many messages the user has sent since the given moment (rate limiting). */
    public function countSentBySince(User $sender, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.sender = :sender')->setParameter('sender', $sender)
            ->andWhere('message.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Marks every unread message in the conversation that the reader did not send as read.
     *
     * @return int the number of messages marked as read
     */
    public function markReadFor(Conversation $conversation, User $reader, \DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()->update(Message::class, 'message')
            ->set('message.readAt', ':now')->setParameter('now', $now)
            ->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->andWhere('message.sender != :user')->setParameter('user', $reader)
            ->andWhere('message.readAt IS NULL')->getQuery()->execute();
    }

    /** Unread messages in the conversation that the reader did not send. */
    public function countUnreadInConversationFor(Conversation $conversation, User $reader): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->andWhere('message.sender != :user')->setParameter('user', $reader)
            ->andWhere('message.readAt IS NULL')->getQuery()->getSingleScalarResult();
    }
}
