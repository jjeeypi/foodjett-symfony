<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Enum\ApprovalStatus;
use App\Enum\RestaurantOperatingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Restaurant>
 */
class RestaurantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Restaurant::class);
    }

    /** @return list<int|string> */
    public function findAllIds(): array
    {
        return $this->createQueryBuilder('restaurant')
            ->select('restaurant.id')->getQuery()->getSingleColumnResult();
    }

    /**
     * Approved restaurants in name order.
     *
     * @return list<Restaurant>
     */
    public function findApproved(): array
    {
        return $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->orderBy('restaurant.name', 'ASC')->getQuery()->getResult();
    }

    public function findOneApproved(int|string $id): ?Restaurant
    {
        return $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.id = :id')->setParameter('id', $id)
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->getQuery()->getOneOrNullResult();
    }

    /** Restaurants with their owners, newest first, optionally filtered by approval and name search. */
    public function createAdminListQuery(?ApprovalStatus $approval = null, string $search = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->orderBy('restaurant.createdAt', 'DESC');
        if (null !== $approval) {
            $query->andWhere('restaurant.approvalStatus = :approval')->setParameter('approval', $approval->value);
        }
        if ('' !== $search) {
            $query->andWhere('LOWER(restaurant.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $query;
    }

    /** Restaurants awaiting approval, oldest application first. */
    public function createPendingQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->andWhere('restaurant.approvalStatus = :status')
            ->setParameter('status', ApprovalStatus::PENDING->value)
            ->orderBy('restaurant.createdAt', 'ASC');
    }

    /** Approved restaurants that are open and whose owner account is active. */
    public function countOpenAndActive(): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->innerJoin('restaurant.user', 'account')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->andWhere('restaurant.operatingStatus = :open')->setParameter('open', RestaurantOperatingStatus::OPEN->value)
            ->andWhere('account.status = :active')->setParameter('active', 'active')
            ->getQuery()->getSingleScalarResult();
    }

    public function countWithApprovalStatus(ApprovalStatus $status): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.approvalStatus = :status')->setParameter('status', $status->value)
            ->getQuery()->getSingleScalarResult();
    }

    /** Records created between $start and $end inclusive. */
    public function countCreatedBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.createdAt >= :start AND restaurant.createdAt <= :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
