<?php

namespace App\Repository;

use App\Entity\Rider;
use App\Enum\ApprovalStatus;
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
}
