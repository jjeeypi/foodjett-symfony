<?php

namespace App\Repository;

use App\Entity\Restaurant;
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

    /**
     * Approved restaurants in name order, with their reviews and opening hours loaded in the same
     * query, because the lists show ratings and open status for every card.
     *
     * @return list<Restaurant>
     */
    public function findApproved(): array
    {
        return $this->createQueryBuilder('restaurant')
            ->addSelect('review', 'hours')
            ->leftJoin('restaurant.reviews', 'review')
            ->leftJoin('restaurant.operatingHours', 'hours')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->orderBy('restaurant.name', 'ASC')->getQuery()->getResult();
    }

    public function findOneApproved(int|string $id): ?Restaurant
    {
        return $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.id = :id')->setParameter('id', $id)
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->getQuery()->getOneOrNullResult();
    }

    public function findOneApprovedBySlug(string $slug): ?Restaurant
    {
        return $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.slug = :slug')->setParameter('slug', $slug)
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->getQuery()->getOneOrNullResult();
    }

    /** Restaurants with their owners, newest first, optionally filtered by approval and name search. */
    public function createAdminListQuery(?string $approval = null, string $search = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->orderBy('restaurant.createdAt', 'DESC');
        if (null !== $approval) {
            $query->andWhere('restaurant.approvalStatus = :approval')->setParameter('approval', $approval);
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
            ->setParameter('status', Restaurant::APPROVAL_PENDING)
            ->orderBy('restaurant.createdAt', 'ASC');
    }

    /** Approved restaurants that are open and whose owner account is active. */
    public function countOpenAndActive(): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->innerJoin('restaurant.user', 'account')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->andWhere('restaurant.operatingStatus = :open')->setParameter('open', Restaurant::OPERATING_OPEN)
            ->andWhere('account.status = :active')->setParameter('active', 'active')
            ->getQuery()->getSingleScalarResult();
    }

    public function countWithApprovalStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.approvalStatus = :status')->setParameter('status', $status)
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

    /** $base, or $base-2, $base-3... whichever no restaurant uses yet. */
    public function nextFreeSlug(string $base): string
    {
        $base = '' !== $base ? $base : 'restaurant';
        $slug = $base;
        for ($suffix = 2; null !== $this->findOneBy(['slug' => $slug]); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
