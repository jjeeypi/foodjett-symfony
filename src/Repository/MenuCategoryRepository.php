<?php

namespace App\Repository;

use App\Entity\MenuCategory;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MenuCategory>
 */
class MenuCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuCategory::class);
    }

    /** The restaurant's menu categories in display order. */
    public function createForRestaurantQuery(Restaurant $restaurant): QueryBuilder
    {
        return $this->createQueryBuilder('category')
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('category.sortOrder', 'ASC')->addOrderBy('category.name', 'ASC');
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?MenuCategory
    {
        return $this->createQueryBuilder('category')
            ->andWhere('category.id = :id')->setParameter('id', $id)
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }
}
