<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderPoolOffer;
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
            static fn (string $status): string => $status,
            Order::TERMINAL_STATUSES,
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
            static fn (string $status): string => $status,
            Order::TERMINAL_STATUSES,
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
            ->setParameter('status', Order::STATUS_FINDING_RIDER);
    }

    /**
     * The customer's orders, newest first, optionally limited to active or past (terminal) orders.
     *
     * @param 'active'|'past'|null $filter
     */
    public function createForCustomerQuery(Customer $customer, ?string $filter = null): QueryBuilder
    {
        $query = $this->createQueryBuilder('orders')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('orders.placedAt', 'DESC');
        $terminal = Order::TERMINAL_STATUSES;
        if ('active' === $filter) {
            $query->andWhere('orders.status NOT IN (:terminal)')->setParameter('terminal', $terminal);
        } elseif ('past' === $filter) {
            $query->andWhere('orders.status IN (:terminal)')->setParameter('terminal', $terminal);
        }

        return $query;
    }

    public function findOneForCustomer(int|string $id, Customer $customer): ?Order
    {
        return $this->createQueryBuilder('orders')
            ->andWhere('orders.id = :id')->setParameter('id', $id)
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getOneOrNullResult();
    }

    /** The restaurant's orders, newest first, optionally filtered by status and order-number search. */
    public function createForRestaurantQuery(Restaurant $restaurant, ?string $status = null, string $search = ''): QueryBuilder
    {
        $builder = $this->createQueryBuilder('orders')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('orders.placedAt', 'DESC');
        if (null !== $status) {
            $builder->andWhere('orders.status = :status')->setParameter('status', $status);
        }
        if ('' !== $search) {
            $builder->andWhere('LOWER(orders.orderNumber) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $builder;
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?Order
    {
        return $this->createQueryBuilder('orders')
            ->andWhere('orders.id = :id')->setParameter('id', $id)
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }

    /** Orders the restaurant received with placedAt in [$start, $end). */
    public function countPlacedForRestaurantBetween(Restaurant $restaurant, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('orders')->select('COUNT(orders.id)')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.placedAt >= :start AND orders.placedAt < :end')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    /** Total of the restaurant's orders delivered in [$start, $end), as a decimal string. */
    public function sumDeliveredTotalForRestaurantBetween(Restaurant $restaurant, \DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        return (string) $this->createQueryBuilder('orders')->select('COALESCE(SUM(orders.totalAmount), 0)')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.status = :status')->setParameter('status', Order::STATUS_DELIVERED)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt < :end')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    public function countForRestaurantWithStatus(Restaurant $restaurant, string $status): int
    {
        return (int) $this->createQueryBuilder('orders')->select('COUNT(orders.id)')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.status = :status')->setParameter('status', $status)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Order> */
    public function findRecentForRestaurant(Restaurant $restaurant, int $limit = 10): array
    {
        return $this->createQueryBuilder('orders')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('orders.placedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * The restaurant's orders delivered between $start and $end inclusive.
     *
     * @return list<Order>
     */
    public function findDeliveredForRestaurantBetween(Restaurant $restaurant, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('orders')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.status = :status')->setParameter('status', Order::STATUS_DELIVERED)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt <= :end')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getResult();
    }

    /** The rider's most recently assigned order that has not reached a terminal status. */
    public function findCurrentForRider(Rider $rider): ?Order
    {
        $terminal = array_map(
            static fn (string $status): string => $status,
            Order::TERMINAL_STATUSES,
        );

        return $this->createQueryBuilder('orders')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('orders.status NOT IN (:terminal)')->setParameter('terminal', $terminal)
            ->orderBy('orders.riderAssignedAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** All orders with their parties loaded, newest first, optionally filtered by status, restaurant and placed date. */
    public function createAdminListQuery(
        ?string $status = null,
        string $restaurantId = '',
        ?\DateTimeImmutable $placedFrom = null,
        ?\DateTimeImmutable $placedTo = null,
    ): QueryBuilder {
        $query = $this->createQueryBuilder('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->innerJoin('orders.customer', 'customer')->addSelect('customer')
            ->innerJoin('customer.user', 'customerUser')->addSelect('customerUser')
            ->leftJoin('orders.rider', 'rider')->addSelect('rider')
            ->leftJoin('rider.user', 'riderUser')->addSelect('riderUser')
            ->orderBy('orders.placedAt', 'DESC');
        if (null !== $status) {
            $query->andWhere('orders.status = :status')->setParameter('status', $status);
        }
        if ('' !== $restaurantId) {
            $query->andWhere('restaurant.id = :restaurantId')->setParameter('restaurantId', $restaurantId);
        }
        if (null !== $placedFrom) {
            $query->andWhere('orders.placedAt >= :date_from')->setParameter('date_from', $placedFrom);
        }
        if (null !== $placedTo) {
            $query->andWhere('orders.placedAt <= :date_to')->setParameter('date_to', $placedTo);
        }

        return $query;
    }

    /** Orders whose rider search escalated to the admin, oldest search first. */
    public function createEscalatedRiderSearchQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('orders')
            ->innerJoin('orders.riderPoolOffer', 'offer')
            ->addSelect('offer')
            ->innerJoin('orders.restaurant', 'restaurant')
            ->addSelect('restaurant')
            ->andWhere('orders.status = :status')
            ->andWhere('orders.rider IS NULL')
            ->andWhere('offer.escalationStage IN (:stages)')
            ->setParameter('status', Order::STATUS_FINDING_RIDER)
            ->setParameter('stages', [
                RiderPoolOffer::STAGE_ADMIN_ALERTED,
                RiderPoolOffer::STAGE_CUSTOMER_NOTIFIED,
                RiderPoolOffer::STAGE_AUTO_CANCELLED,
            ])
            ->orderBy('orders.riderSearchStartedAt', 'ASC');
    }

    /**
     * Delivered orders between $startAt and $endAt with their restaurant loaded.
     *
     * @return list<Order>
     */
    public function findDeliveredBetween(\DateTimeImmutable $startAt, \DateTimeImmutable $endAt): array
    {
        return $this->createQueryBuilder('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('orders.status = :status')->setParameter('status', Order::STATUS_DELIVERED)
            ->andWhere('orders.deliveredAt BETWEEN :startAt AND :endAt')->setParameter('startAt', $startAt)->setParameter('endAt', $endAt)
            ->getQuery()->getResult();
    }

    /**
     * Order count and lifetime spend for one customer.
     *
     * @return array{order_count: int|string, lifetime_spend: string}
     */
    public function summarizeForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('orders')
            ->select('COUNT(orders.id) AS order_count, COALESCE(SUM(orders.totalAmount), 0) AS lifetime_spend')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getSingleResult();
    }

    /** @return list<Order> */
    public function findRecentForCustomer(Customer $customer, int $limit = 10): array
    {
        return $this->createQueryBuilder('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('orders.placedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /** Orders placed in [$start, $end). */
    public function countPlacedBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('orders')
            ->select('COUNT(orders.id)')
            ->andWhere('orders.placedAt >= :start AND orders.placedAt < :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    /** @param list<string> $statuses */
    public function countWithStatuses(array $statuses): int
    {
        return (int) $this->createQueryBuilder('orders')
            ->select('COUNT(orders.id)')
            ->andWhere('orders.status IN (:statuses)')
            ->setParameter('statuses', array_map(static fn (string $status): string => $status, $statuses))
            ->getQuery()->getSingleScalarResult();
    }

    /** Orders delivered in [$start, $end). */
    public function countDeliveredBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('orders')
            ->select('COUNT(orders.id)')
            ->andWhere('orders.status = :status')->setParameter('status', Order::STATUS_DELIVERED)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt < :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    /** Total of all orders delivered in [$start, $end), as a decimal string. */
    public function sumDeliveredTotalBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        return (string) $this->createQueryBuilder('orders')
            ->select('COALESCE(SUM(orders.totalAmount), 0)')
            ->andWhere('orders.status = :status')->setParameter('status', Order::STATUS_DELIVERED)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt < :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
