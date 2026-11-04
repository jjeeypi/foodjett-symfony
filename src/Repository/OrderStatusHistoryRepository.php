<?php

namespace App\Repository;

use App\Entity\OrderStatusHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderStatusHistory>
 */
class OrderStatusHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderStatusHistory::class);
    }

    /**
     * Distinct orders that entered one of the statuses in [$start, $end).
     *
     * @param list<string> $statuses
     */
    public function countOrdersEnteringStatusesBetween(array $statuses, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('history')
            ->select('COUNT(DISTINCT IDENTITY(history.order))')
            ->andWhere('history.status IN (:statuses)')->setParameter('statuses', $statuses)
            ->andWhere('history.createdAt >= :start AND history.createdAt < :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
