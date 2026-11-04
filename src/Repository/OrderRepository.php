<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\Rider;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /** @return list<int|string> */
    public function findIdsForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('orders')
            ->select('orders.id')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getSingleColumnResult();
    }

    /**
     * IDs of the rider's orders that have not reached a terminal status.
     *
     * @return list<int|string>
     */
    public function findActiveIdsForRider(Rider $rider): array
    {
        $terminalStatuses = array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isTerminal()),
        );

        return $this->createQueryBuilder('orders')
            ->select('orders.id')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('orders.status NOT IN (:terminalStatuses)')->setParameter('terminalStatuses', $terminalStatuses)
            ->getQuery()->getSingleColumnResult();
    }

    /** @return list<int|string> */
    public function findAllIds(): array
    {
        return $this->createQueryBuilder('orders')
            ->select('orders.id')->getQuery()->getSingleColumnResult();
    }

    /**
     * Orders still searching for a rider, oldest search first, with their pool offer,
     * restaurant and delivery address loaded for the rider pool.
     *
     * @return list<Order>
     */
    public function findAwaitingRiderForPool(int $limit = 100): array
    {
        return $this->awaitingRiderQuery()
            ->innerJoin('orders.restaurant', 'restaurant')
            ->addSelect('restaurant')
            ->innerJoin('orders.customerAddress', 'address')
            ->addSelect('address')
            ->orderBy('orders.riderSearchStartedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every order still searching for a rider, with its pool offer and restaurant loaded.
     *
     * @return list<Order>
     */
    public function findAwaitingRiderWithRestaurant(): array
    {
        return $this->awaitingRiderQuery()
            ->innerJoin('orders.restaurant', 'restaurant')
            ->addSelect('restaurant')
            ->getQuery()
            ->getResult();
    }

    /**
     * Orders still searching for a rider, oldest search first, for the escalation schedule.
     *
     * @return list<Order>
     */
    public function findAwaitingRiderForEscalation(int $limit = 500): array
    {
        return $this->awaitingRiderQuery()
            ->orderBy('orders.riderSearchStartedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the rider already holds a non-terminal order other than $excludeOrderId.
     */
    public function riderHasOtherActiveOrder(Rider $rider, int|string|null $excludeOrderId = null): bool
    {
        $terminal = array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isTerminal())),
        );
        $builder = $this->createQueryBuilder('active')
            ->select('COUNT(active.id)')
            ->andWhere('active.rider = :rider')
            ->andWhere('active.status NOT IN (:terminal)')
            ->setParameter('rider', $rider)
            ->setParameter('terminal', $terminal);
        if (null !== $excludeOrderId) {
            $builder->andWhere('active.id != :candidate')->setParameter('candidate', $excludeOrderId);
        }

        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }

    private function awaitingRiderQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('orders')
            ->innerJoin('orders.riderPoolOffer', 'offer')
            ->addSelect('offer')
            ->andWhere('orders.status = :status')
            ->andWhere('orders.rider IS NULL')
            ->setParameter('status', OrderStatus::FINDING_RIDER);
    }
}
