<?php

namespace App\Repository;

use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Enum\RemittanceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiderCashRemittance>
 */
class RiderCashRemittanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiderCashRemittance::class);
    }

    /** The rider's cash remittances, newest first. */
    public function createForRiderQuery(Rider $rider): QueryBuilder
    {
        return $this->createQueryBuilder('remittance')
            ->andWhere('remittance.rider = :rider')->setParameter('rider', $rider)
            ->orderBy('remittance.createdAt', 'DESC');
    }

    /** Sum of the rider's remittances still pending review, as a decimal string. */
    public function sumPendingForRider(Rider $rider): string
    {
        return (string) $this->createQueryBuilder('remittance')
            ->select('COALESCE(SUM(remittance.amount), 0)')->andWhere('remittance.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('remittance.status = :status')->setParameter('status', RemittanceStatus::PENDING->value)
            ->getQuery()->getSingleScalarResult();
    }
}
