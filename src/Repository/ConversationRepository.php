<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /** @return list<int|string> */
    public function findIdsForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('conversation')
            ->select('conversation.id')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getSingleColumnResult();
    }

    /** @return list<int|string> */
    public function findCustomerRiderIdsForRider(Rider $rider): array
    {
        return $this->createQueryBuilder('conversation')
            ->select('conversation.id')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('conversation.type = :type')->setParameter('type', Conversation::TYPE_CUSTOMER_RIDER)
            ->getQuery()->getSingleColumnResult();
    }

    /** @return list<int|string> */
    public function findAllIds(): array
    {
        return $this->createQueryBuilder('conversation')
            ->select('conversation.id')->getQuery()->getSingleColumnResult();
    }

    /** The customer's order conversations, most recently active first. */
    /**
     * Every conversation the user takes part in, most recently active first: as the order's
     * customer, as its restaurant (restaurant chats) or as its rider (rider chats).
     */
    public function createForParticipantQuery(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')->addSelect('orders')
            ->innerJoin('orders.customer', 'customer')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->leftJoin('orders.rider', 'rider')
            ->andWhere('customer.user = :user'
                .' OR (conversation.type = :restaurantChat AND restaurant.user = :user)'
                .' OR (conversation.type = :riderChat AND rider.user = :user)')
            ->setParameter('user', $user)
            ->setParameter('restaurantChat', Conversation::TYPE_CUSTOMER_RESTAURANT)
            ->setParameter('riderChat', Conversation::TYPE_CUSTOMER_RIDER)
            ->orderBy('conversation.updatedAt', 'DESC')->addOrderBy('conversation.id', 'DESC');
    }

    public function createForCustomerQuery(Customer $customer): QueryBuilder
    {
        return $this->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('conversation.updatedAt', 'DESC')->addOrderBy('conversation.createdAt', 'DESC');
    }

    public function findOneForCustomer(int|string $id, Customer $customer): ?Conversation
    {
        return $this->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('conversation.id = :id')->setParameter('id', $id)
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getOneOrNullResult();
    }

    /** The rider's customer-rider conversations, most recently active first. */
    public function createCustomerRiderForRiderQuery(Rider $rider): QueryBuilder
    {
        return $this->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('conversation.type = :type')->setParameter('type', Conversation::TYPE_CUSTOMER_RIDER)
            ->orderBy('conversation.updatedAt', 'DESC')->addOrderBy('conversation.createdAt', 'DESC');
    }

    public function findOneCustomerRiderForRider(int|string $id, Rider $rider): ?Conversation
    {
        return $this->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('conversation.id = :id')->setParameter('id', $id)
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('conversation.type = :type')->setParameter('type', Conversation::TYPE_CUSTOMER_RIDER)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * The order's conversation of this type, created (and queued for saving) when it does not exist yet.
     * A customer-rider conversation needs an assigned rider.
     */
    public function forOrder(Order $order, string $type, ?\DateTimeImmutable $now = null): Conversation
    {
        if (Conversation::TYPE_CUSTOMER_RIDER === $type && null === $order->getRider()) {
            throw new \DomainException('A rider must be assigned before opening this conversation.');
        }

        foreach ($order->getConversations() as $conversation) {
            if ($conversation->getType() === $type) {
                return $conversation;
            }
        }

        $existing = $this->findOneBy(['order' => $order, 'type' => $type]);
        if ($existing instanceof Conversation) {
            return $existing;
        }

        $now ??= new \DateTimeImmutable();
        $conversation = (new Conversation())
            ->setOrder($order)
            ->setType($type)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->addConversation($conversation);
        $this->getEntityManager()->persist($conversation);

        return $conversation;
    }
}
