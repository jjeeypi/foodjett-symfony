<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Favorite;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favorite>
 */
class FavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favorite::class);
    }

    /**
     * The approved restaurants a customer saved, most recent first.
     *
     * @return list<Restaurant>
     */
    public function findRestaurantsFor(Customer $customer): array
    {
        return array_map(static fn (Favorite $f) => $f->getRestaurant(), $this->createQueryBuilder('f')
            ->addSelect('r')
            ->join('f.restaurant', 'r')
            ->andWhere('f.customer = :customer')
            ->andWhere('r.approvalStatus = :approved')
            ->setParameter('customer', $customer)
            ->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Ids of the restaurants a customer saved, for drawing filled hearts.
     *
     * @return list<string>
     */
    public function findRestaurantIdsFor(?Customer $customer): array
    {
        if (null === $customer) {
            return [];
        }

        return array_map('strval', array_column($this->createQueryBuilder('f')
            ->select('IDENTITY(f.restaurant) AS id')
            ->andWhere('f.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getScalarResult(), 'id'));
    }
}
