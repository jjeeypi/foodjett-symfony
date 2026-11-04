<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
