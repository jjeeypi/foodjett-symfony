<?php

namespace App\Repository;

use App\Entity\Rider;
use App\Entity\RiderPayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiderPayout>
 */
class RiderPayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiderPayout::class);
    }

    /** The rider's payouts, latest period first. */
    public function createForRiderQuery(Rider $rider): QueryBuilder
    {
        return $this->createQueryBuilder('payout')
            ->andWhere('payout.rider = :rider')->setParameter('rider', $rider)
            ->orderBy('payout.periodEnd', 'DESC');
    }

    /** Every rider payout for the admin list, latest period first, optionally filtered. */
    public function createAdminListQuery(?string $status = null, string $riderId = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('payout')
            ->innerJoin('payout.rider', 'rider')->addSelect('rider')->innerJoin('rider.user', 'riderUser')->addSelect('riderUser')
            ->orderBy('payout.periodEnd', 'DESC')->addOrderBy('payout.id', 'DESC');
        if (null !== $status) {
            $query->andWhere('payout.status = :status')->setParameter('status', $status);
        }
        if ('' !== $riderId) {
            $query->andWhere('rider.id = :riderId')->setParameter('riderId', $riderId);
        }

        return $query;
    }

    /** How many of the rider's payouts overlap the period [$start, $end]. */
    public function countOverlapping(Rider $rider, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('existing')
            ->select('COUNT(existing.id)')->andWhere('existing.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('existing.periodStart <= :end AND existing.periodEnd >= :start')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
