<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Voucher;
use App\Entity\VoucherRedemption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VoucherRedemption>
 */
class VoucherRedemptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoucherRedemption::class);
    }

    public function countForVoucher(Voucher $voucher): int
    {
        return (int) $this->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.voucher = :voucher')
            ->setParameter('voucher', $voucher)
            ->getQuery()->getSingleScalarResult();
    }

    public function countForVoucherAndCustomer(Voucher $voucher, Customer $customer): int
    {
        return (int) $this->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.voucher = :voucher')
            ->andWhere('redemption.customer = :customer')
            ->setParameter('voucher', $voucher)
            ->setParameter('customer', $customer)
            ->getQuery()->getSingleScalarResult();
    }

    /** Redemptions made in [$start, $end). */
    public function countBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.createdAt >= :start AND redemption.createdAt < :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }
}
