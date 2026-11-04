<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Entity\RestaurantPayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RestaurantPayout>
 */
class RestaurantPayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RestaurantPayout::class);
    }

    /** The restaurant's payouts, latest period first. */
    public function createForRestaurantQuery(Restaurant $restaurant): QueryBuilder
    {
        return $this->createQueryBuilder('payout')
            ->andWhere('payout.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('payout.periodEnd', 'DESC');
    }

    /** Every restaurant payout for the admin list, latest period first, optionally filtered. */
    public function createAdminListQuery(?string $status = null, string $restaurantId = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('payout')
            ->innerJoin('payout.restaurant', 'restaurant')->addSelect('restaurant')
            ->orderBy('payout.periodEnd', 'DESC')->addOrderBy('payout.id', 'DESC');
        if (null !== $status) {
            $query->andWhere('payout.status = :status')->setParameter('status', $status);
        }
        if ('' !== $restaurantId) {
            $query->andWhere('restaurant.id = :restaurantId')->setParameter('restaurantId', $restaurantId);
        }

        return $query;
    }

    /** How many of the restaurant's payouts overlap the period [$start, $end]. */
    public function countOverlapping(Restaurant $restaurant, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('existing')
            ->select('COUNT(existing.id)')->andWhere('existing.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('existing.periodStart <= :end AND existing.periodEnd >= :start')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
