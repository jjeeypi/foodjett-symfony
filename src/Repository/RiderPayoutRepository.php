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
}
