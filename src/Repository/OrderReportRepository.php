<?php

namespace App\Repository;

use App\Entity\OrderReport;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
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

    /** Reports the user filed, newest first, with each order and its restaurant. */
    public function createForReporterQuery(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('report')
            ->innerJoin('report.order', 'orders')->addSelect('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('report.reportedBy = :user')->setParameter('user', $user)
            ->orderBy('report.createdAt', 'DESC')
            ->addOrderBy('report.id', 'DESC');
    }

    /** Reports about the restaurant on its own orders, newest first. */
    public function createAgainstRestaurantQuery(Restaurant $restaurant): QueryBuilder
    {
        return $this->againstQuery(OrderReport::AGAINST_RESTAURANT)
            ->andWhere('orders.restaurant = :subject')->setParameter('subject', $restaurant);
    }

    /** Reports about the rider on orders they delivered, newest first. */
    public function createAgainstRiderQuery(Rider $rider): QueryBuilder
    {
        return $this->againstQuery(OrderReport::AGAINST_RIDER)
            ->andWhere('orders.rider = :subject')->setParameter('subject', $rider);
    }

    /**
     * Counts for the summary above the restaurant's report list.
     *
     * @return array{total: int, open: int, resolved: int, rejected: int, by_type: array<string, int>}
     */
    public function summarizeAgainstRestaurant(Restaurant $restaurant): array
    {
        return $this->summarize($this->createAgainstRestaurantQuery($restaurant));
    }

    /**
     * Counts for the summary above the rider's report list.
     *
     * @return array{total: int, open: int, resolved: int, rejected: int, by_type: array<string, int>}
     */
    public function summarizeAgainstRider(Rider $rider): array
    {
        return $this->summarize($this->createAgainstRiderQuery($rider));
    }

    private function againstQuery(string $against): QueryBuilder
    {
        return $this->createQueryBuilder('report')
            ->innerJoin('report.order', 'orders')->addSelect('orders')
            ->innerJoin('report.reportedBy', 'reporter')->addSelect('reporter')
            ->andWhere('report.against = :against')->setParameter('against', $against)
            ->orderBy('report.createdAt', 'DESC')
            ->addOrderBy('report.id', 'DESC');
    }

    /**
     * Groups a report query by type and status. Open counts both open and under-review reports.
     *
     * @return array{total: int, open: int, resolved: int, rejected: int, by_type: array<string, int>}
     */
    private function summarize(QueryBuilder $query): array
    {
        $rows = (clone $query)
            ->select('report.type AS type', 'report.status AS status', 'COUNT(report.id) AS reports')
            ->resetDQLPart('orderBy')
            ->groupBy('report.type')
            ->addGroupBy('report.status')
            ->getQuery()
            ->getArrayResult();

        $summary = ['total' => 0, 'open' => 0, 'resolved' => 0, 'rejected' => 0, 'by_type' => []];
        foreach ($rows as $row) {
            $count = (int) $row['reports'];
            $summary['total'] += $count;
            $summary['by_type'][$row['type']] = ($summary['by_type'][$row['type']] ?? 0) + $count;
            match ($row['status']) {
                OrderReport::STATUS_RESOLVED => $summary['resolved'] += $count,
                OrderReport::STATUS_REJECTED => $summary['rejected'] += $count,
                default => $summary['open'] += $count,
            };
        }
        arsort($summary['by_type']);

        return $summary;
    }
}
