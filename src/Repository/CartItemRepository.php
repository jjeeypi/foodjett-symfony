<?php

namespace App\Repository;

use App\Entity\CartItem;
use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CartItem>
 */
class CartItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartItem::class);
    }

    /**
     * The customer's cart lines with their menu item, restaurant and variant, oldest first.
     *
     * @return list<CartItem>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('line')
            ->innerJoin('line.menuItem', 'item')->addSelect('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->innerJoin('category.restaurant', 'restaurant')->addSelect('restaurant')
            ->leftJoin('line.variant', 'variant')->addSelect('variant')
            ->andWhere('line.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('line.createdAt', 'ASC')->addOrderBy('line.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOneForCustomer(int|string $id, Customer $customer): ?CartItem
    {
        return $this->createQueryBuilder('line')
            ->andWhere('line.id = :id')->setParameter('id', $id)
            ->andWhere('line.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getOneOrNullResult();
    }

    /** Empties the customer's cart; returns how many lines were removed. */
    public function removeAllForCustomer(Customer $customer): int
    {
        return $this->createQueryBuilder('line')
            ->delete()
            ->andWhere('line.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->execute();
    }
}
