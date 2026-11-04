<?php

namespace App\Repository;

use App\Entity\OrderReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderReport>
 */
class OrderReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderReport::class);
    }

    /** Order reports with their order, reporter and resolver, newest first, optionally filtered. */
    public function createAdminListQuery(
        ?string $status = null,
        ?string $type = null,
        ?string $against = null,
    ): QueryBuilder {
        $query = $this->createQueryBuilder('report')
            ->innerJoin('report.order', 'orders')->addSelect('orders')
            ->innerJoin('report.reportedBy', 'reporter')->addSelect('reporter')
            ->leftJoin('report.resolvedByAdmin', 'resolver')->addSelect('resolver')
            ->orderBy('report.createdAt', 'DESC');
        foreach (['status' => $status, 'type' => $type, 'against' => $against] as $field => $value) {
            if (null !== $value) {
                $query->andWhere('report.'.$field.' = :'.$field)->setParameter($field, $value);
            }
        }

        return $query;
    }
}
