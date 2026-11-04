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

    /** Every remittance with its rider and reviewing admin, oldest first, optionally filtered by status. */
    public function createAdminListQuery(?RemittanceStatus $status = null): QueryBuilder
    {
        $query = $this->createQueryBuilder('remittance')
            ->innerJoin('remittance.rider', 'rider')->addSelect('rider')->innerJoin('rider.user', 'riderUser')->addSelect('riderUser')
            ->leftJoin('remittance.confirmedByAdmin', 'admin')->addSelect('admin')
            ->orderBy('remittance.createdAt', 'ASC');
        if (null !== $status) {
            $query->andWhere('remittance.status = :status')->setParameter('status', $status->value);
        }

        return $query;
    }

    /** Sum of every remittance still pending review, as a decimal string. */
    public function sumPending(): string
    {
        return (string) $this->createQueryBuilder('remittance')
            ->select('COALESCE(SUM(remittance.amount), 0)')
            ->andWhere('remittance.status = :status')->setParameter('status', RemittanceStatus::PENDING->value)
            ->getQuery()->getSingleScalarResult();
    }
}
