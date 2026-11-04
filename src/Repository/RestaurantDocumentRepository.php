<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RestaurantDocument>
 */
class RestaurantDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RestaurantDocument::class);
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?RestaurantDocument
    {
        return $this->createQueryBuilder('document')
            ->andWhere('document.id = :id')->setParameter('id', $id)
            ->andWhere('document.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }
}
