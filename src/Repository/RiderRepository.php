<?php

namespace App\Repository;

use App\Entity\Rider;
use App\Enum\ApprovalStatus;
use App\Enum\RiderAvailabilityStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rider>
 */
class RiderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rider::class);
    }

    /** Riders with their accounts, newest first, optionally filtered by approval and name or plate search. */
    public function createAdminListQuery(?ApprovalStatus $approval = null, string $search = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('rider')
            ->innerJoin('rider.user', 'owner')->addSelect('owner')
            ->orderBy('rider.createdAt', 'DESC');
        if (null !== $approval) {
            $query->andWhere('rider.approvalStatus = :approval')->setParameter('approval', $approval->value);
        }
        if ('' !== $search) {
            $query->andWhere('LOWER(owner.name) LIKE :search OR LOWER(COALESCE(rider.plateNumber, \'\')) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $query;
    }

    /** Riders awaiting approval, oldest application first. */
    public function createPendingQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('rider')
            ->innerJoin('rider.user', 'owner')->addSelect('owner')
            ->andWhere('rider.approvalStatus = :status')
            ->setParameter('status', ApprovalStatus::PENDING->value)
            ->orderBy('rider.createdAt', 'ASC');
    }

    /** Approved riders who are available or busy and whose account is active. */
    public function countOnline(): int
    {
        return (int) $this->createQueryBuilder('rider')
            ->select('COUNT(rider.id)')
            ->innerJoin('rider.user', 'account')
            ->andWhere('rider.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->andWhere('rider.availabilityStatus IN (:online)')->setParameter('online', [RiderAvailabilityStatus::AVAILABLE->value, RiderAvailabilityStatus::BUSY->value])
            ->andWhere('account.status = :active')->setParameter('active', 'active')
            ->getQuery()->getSingleScalarResult();
    }

    public function countWithApprovalStatus(ApprovalStatus $status): int
    {
        return (int) $this->createQueryBuilder('rider')
            ->select('COUNT(rider.id)')
            ->andWhere('rider.approvalStatus = :status')->setParameter('status', $status->value)
            ->getQuery()->getSingleScalarResult();
    }

    /** Records created between $start and $end inclusive. */
    public function countCreatedBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('rider')
            ->select('COUNT(rider.id)')
            ->andWhere('rider.createdAt >= :start AND rider.createdAt <= :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
