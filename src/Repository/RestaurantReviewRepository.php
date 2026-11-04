<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Entity\RestaurantReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RestaurantReview>
 */
class RestaurantReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RestaurantReview::class);
    }

    /** A restaurant's reviews, newest first. */
    public function createForRestaurantQuery(Restaurant $restaurant): QueryBuilder
    {
        return $this->createQueryBuilder('review')
            ->andWhere('review.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('review.createdAt', 'DESC');
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?RestaurantReview
    {
        return $this->createQueryBuilder('review')
            ->andWhere('review.id = :id')->setParameter('id', $id)
            ->andWhere('review.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }
}
