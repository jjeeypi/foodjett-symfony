<?php

namespace App\Repository;

use App\Entity\MenuItem;
use App\Enum\ApprovalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MenuItem>
 */
class MenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItem::class);
    }

    /**
     * Available menu items of approved restaurants, with their category and restaurant loaded.
     *
     * @return list<MenuItem>
     */
    public function findAvailableFromApprovedRestaurants(): array
    {
        return $this->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->innerJoin('category.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('item.isAvailable = true')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->getQuery()->getResult();
    }
}
