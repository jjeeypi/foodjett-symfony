<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Rider;
use App\Enum\ConversationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ->andWhere('conversation.type = :type')->setParameter('type', ConversationType::CUSTOMER_RIDER)
            ->getQuery()->getSingleColumnResult();
    }

    /** @return list<int|string> */
    public function findAllIds(): array
    {
        return $this->createQueryBuilder('conversation')
            ->select('conversation.id')->getQuery()->getSingleColumnResult();
    }
}
