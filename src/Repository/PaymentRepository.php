<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /** Payments with their order parties, newest first, optionally filtered by method, status and paid date. */
    public function createAdminListQuery(
        ?string $method = null,
        ?string $status = null,
        ?\DateTimeImmutable $paidFrom = null,
        ?\DateTimeImmutable $paidTo = null,
    ): QueryBuilder {
        $query = $this->createQueryBuilder('payment')
            ->innerJoin('payment.order', 'orders')->addSelect('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->innerJoin('orders.customer', 'customer')->addSelect('customer')
            ->innerJoin('customer.user', 'customerUser')->addSelect('customerUser')
            ->orderBy('payment.createdAt', 'DESC');
        if (null !== $method) {
            $query->andWhere('payment.method = :method')->setParameter('method', $method);
        }
        if (null !== $status) {
            $query->andWhere('payment.status = :status')->setParameter('status', $status);
        }
        if (null !== $paidFrom) {
            $query->andWhere('payment.paidAt >= :date_from')->setParameter('date_from', $paidFrom);
        }
        if (null !== $paidTo) {
            $query->andWhere('payment.paidAt <= :date_to')->setParameter('date_to', $paidTo);
        }

        return $query;
    }
}
