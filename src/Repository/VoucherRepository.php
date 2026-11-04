<?php

namespace App\Repository;

use App\Entity\Restaurant;
use App\Entity\Voucher;
use App\Enum\VoucherScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Voucher>
 */
class VoucherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voucher::class);
    }

    /** The restaurant's own vouchers, newest first. */
    public function createForRestaurantQuery(Restaurant $restaurant): QueryBuilder
    {
        return $this->createQueryBuilder('voucher')
            ->andWhere('voucher.scope = :scope')->setParameter('scope', VoucherScope::RESTAURANT->value)
            ->andWhere('voucher.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('voucher.createdAt', 'DESC');
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?Voucher
    {
        return $this->createQueryBuilder('voucher')
            ->andWhere('voucher.id = :id')->setParameter('id', $id)
            ->andWhere('voucher.scope = :scope')->setParameter('scope', VoucherScope::RESTAURANT->value)
            ->andWhere('voucher.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }

    /** Platform-wide vouchers, newest first. */
    public function createPlatformListQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('voucher')
            ->andWhere('voucher.scope = :scope')->setParameter('scope', VoucherScope::PLATFORM->value)
            ->orderBy('voucher.createdAt', 'DESC');
    }
}
