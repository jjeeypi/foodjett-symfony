<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Enum\ApprovalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
