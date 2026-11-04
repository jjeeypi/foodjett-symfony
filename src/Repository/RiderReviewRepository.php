<?php

namespace App\Repository;

use App\Entity\RiderReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiderReview>
 */
class RiderReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiderReview::class);
    }

    /** Every rider review with its order, customer and rider, newest first, optionally only those at or below $maxRating stars. */
    public function createAdminListQuery(?int $maxRating = null): QueryBuilder
    {
        $query = $this->createQueryBuilder('review')
            ->innerJoin('review.order', 'orders')->addSelect('orders')
            ->innerJoin('review.rider', 'rider')->addSelect('rider')
            ->innerJoin('rider.user', 'riderUser')->addSelect('riderUser')
            ->innerJoin('review.customer', 'customer')->addSelect('customer')
            ->innerJoin('customer.user', 'customerUser')->addSelect('customerUser')
            ->orderBy('review.createdAt', 'DESC');
        if (null !== $maxRating) {
            $query->andWhere('review.rating <= :maxRating')->setParameter('maxRating', $maxRating);
        }

        return $query;
    }

//    /**
//     * @return RiderReview[] Returns an array of RiderReview objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('r')
//            ->andWhere('r.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('r.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?RiderReview
//    {
//        return $this->createQueryBuilder('r')
//            ->andWhere('r.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
