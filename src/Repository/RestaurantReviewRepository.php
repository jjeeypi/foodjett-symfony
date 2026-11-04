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

    /** Every restaurant review with its order, customer and restaurant, newest first, optionally only those at or below $maxRating stars. */
    public function createAdminListQuery(?int $maxRating = null): QueryBuilder
    {
        $query = $this->createQueryBuilder('review')
            ->innerJoin('review.order', 'orders')->addSelect('orders')
            ->innerJoin('review.restaurant', 'restaurant')->addSelect('restaurant')
            ->innerJoin('review.customer', 'customer')->addSelect('customer')
            ->innerJoin('customer.user', 'customerUser')->addSelect('customerUser')
            ->orderBy('review.createdAt', 'DESC');
        if (null !== $maxRating) {
            $query->andWhere('review.rating <= :maxRating')->setParameter('maxRating', $maxRating);
        }

        return $query;
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?RestaurantReview
    {
        return $this->createQueryBuilder('review')
            ->andWhere('review.id = :id')->setParameter('id', $id)
            ->andWhere('review.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }
}
