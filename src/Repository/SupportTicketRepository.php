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

    /** Every support ticket with its account, newest first, optionally filtered by status. */
    public function createAdminListQuery(?string $status = null): QueryBuilder
    {
        $query = $this->createQueryBuilder('ticket')
            ->innerJoin('ticket.user', 'account')->addSelect('account')->orderBy('ticket.createdAt', 'DESC');
        if (null !== $status) {
            $query->andWhere('ticket.status = :status')->setParameter('status', $status);
        }

        return $query;
    }

    /** @param list<string> $statuses */
    public function countWithStatuses(array $statuses): int
    {
        return (int) $this->createQueryBuilder('ticket')
            ->select('COUNT(ticket.id)')
            ->andWhere('ticket.status IN (:statuses)')
            ->setParameter('statuses', array_map(static fn (string $status): string => $status, $statuses))
            ->getQuery()->getSingleScalarResult();
    }
}
