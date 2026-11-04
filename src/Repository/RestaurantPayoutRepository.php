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
}
