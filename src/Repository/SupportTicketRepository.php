<?php

namespace App\Repository;

use App\Entity\SupportTicket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportTicket>
 */
class SupportTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportTicket::class);
    }

    /** Support tickets the user opened, newest first, ready for pagination. */
    public function createForUserQuery(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.user = :user')->setParameter('user', $user)
            ->orderBy('ticket.createdAt', 'DESC');
    }
}
