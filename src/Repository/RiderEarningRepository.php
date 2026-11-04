<?php

namespace App\Repository;

use App\Entity\Rider;
use App\Entity\RiderEarning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiderEarning>
 */
class RiderEarningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiderEarning::class);
    }

    /** The rider's earnings with their order and restaurant, newest first. */
    public function createForRiderQuery(Rider $rider): QueryBuilder
    {
        return $this->createQueryBuilder('earning')
            ->innerJoin('earning.order', 'orders')->addSelect('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('earning.rider = :rider')->setParameter('rider', $rider)
            ->orderBy('earning.createdAt', 'DESC');
    }

    /** Total the rider earned since the given moment, as a decimal string. */
    public function sumEarnedByRiderSince(Rider $rider, \DateTimeImmutable $since): string
    {
        return (string) $this->createQueryBuilder('earning')
            ->select('COALESCE(SUM(earning.totalEarned), 0)')->andWhere('earning.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('earning.createdAt >= :since')->setParameter('since', $since)->getQuery()->getSingleScalarResult();
    }
}
