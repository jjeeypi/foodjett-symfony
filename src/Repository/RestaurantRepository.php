<?php

namespace App\Repository;

use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Restaurant>
 */
class RestaurantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Restaurant::class);
    }

    /**
     * Approved restaurants in name order, with their reviews and opening hours loaded in the same
     * query, because the lists show ratings and open status for every card.
     *
     * @return list<Restaurant>
     */
    public function findApproved(): array
    {
        return $this->createQueryBuilder('restaurant')
            ->addSelect('review', 'hours')
            ->leftJoin('restaurant.reviews', 'review')
            ->leftJoin('restaurant.operatingHours', 'hours')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->orderBy('restaurant.name', 'ASC')->getQuery()->getResult();
    }

    /**
     * Approved restaurants for the search engine. Every term must appear in the name, cuisine or description.
     * Best match first: the words typed together in the name, then a word of the name starting with a term,
     * then a name containing it, then the cuisine, then the description.
     *
     * @param list<string> $terms lowercase letters and digits only, from SearchController::terms()
     *
     * @return list<Restaurant>
     */
    public function search(array $terms, int $limit = 10): array
    {
        $query = $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->setMaxResults($limit);

        $score = [];
        foreach (array_values($terms) as $i => $term) {
            $query->andWhere("(LOWER(restaurant.name) LIKE :any$i OR LOWER(restaurant.cuisineType) LIKE :any$i OR LOWER(restaurant.description) LIKE :any$i)")
                ->setParameter("any$i", '%'.$term.'%')->setParameter("start$i", $term.'%')->setParameter("word$i", '% '.$term.'%');
            $score[] = "CASE WHEN LOWER(restaurant.name) LIKE :start$i OR LOWER(restaurant.name) LIKE :word$i THEN 30 WHEN LOWER(restaurant.name) LIKE :any$i THEN 15 ELSE 0 END";
            $score[] = "CASE WHEN LOWER(restaurant.cuisineType) LIKE :any$i THEN 8 ELSE 0 END";
            $score[] = "CASE WHEN LOWER(restaurant.description) LIKE :any$i THEN 2 ELSE 0 END";
        }
        if ([] === $score) {
            return [];
        }
        if (count($terms) > 1) {
            $score[] = 'CASE WHEN LOWER(restaurant.name) LIKE :phrase THEN 50 ELSE 0 END';
            $query->setParameter('phrase', '%'.implode(' ', $terms).'%');
        }

        return $query->addSelect('('.implode(' + ', $score).') AS HIDDEN relevance')
            ->orderBy('relevance', 'DESC')->addOrderBy('restaurant.name', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Names and cuisines of approved restaurants: the words "did you mean" can offer.
     *
     * @return list<string>
     */
    public function findSearchWords(): array
    {
        $rows = $this->createQueryBuilder('restaurant')
            ->select('restaurant.name AS name', 'restaurant.cuisineType AS cuisine')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->getQuery()->getArrayResult();

        return array_values(array_filter([...array_column($rows, 'name'), ...array_column($rows, 'cuisine')]));
    }

    public function findOneApproved(int|string $id): ?Restaurant
    {
        return $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.id = :id')->setParameter('id', $id)
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->getQuery()->getOneOrNullResult();
    }

    public function findOneApprovedBySlug(string $slug): ?Restaurant
    {
        return $this->createQueryBuilder('restaurant')
            ->andWhere('restaurant.slug = :slug')->setParameter('slug', $slug)
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->getQuery()->getOneOrNullResult();
    }

    /** Restaurants with their owners, newest first, optionally filtered by approval and name search. */
    public function createAdminListQuery(?string $approval = null, string $search = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->orderBy('restaurant.createdAt', 'DESC');
        if (null !== $approval) {
            $query->andWhere('restaurant.approvalStatus = :approval')->setParameter('approval', $approval);
        }
        if ('' !== $search) {
            $query->andWhere('LOWER(restaurant.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $query;
    }

    /** Restaurants awaiting approval, oldest application first. */
    public function createPendingQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->andWhere('restaurant.approvalStatus = :status')
            ->setParameter('status', Restaurant::APPROVAL_PENDING)
            ->orderBy('restaurant.createdAt', 'ASC');
    }

    /** Approved restaurants that are open and whose owner account is active. */
    public function countOpenAndActive(): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->innerJoin('restaurant.user', 'account')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->andWhere('restaurant.operatingStatus = :open')->setParameter('open', Restaurant::OPERATING_OPEN)
            ->andWhere('account.status = :active')->setParameter('active', 'active')
            ->getQuery()->getSingleScalarResult();
    }

    public function countWithApprovalStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.approvalStatus = :status')->setParameter('status', $status)
            ->getQuery()->getSingleScalarResult();
    }

    /** Records created between $start and $end inclusive. */
    public function countCreatedBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.createdAt >= :start AND restaurant.createdAt <= :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    /** $base, or $base-2, $base-3... whichever no restaurant uses yet. */
    public function nextFreeSlug(string $base): string
    {
        $base = '' !== $base ? $base : 'restaurant';
        $slug = $base;
        for ($suffix = 2; null !== $this->findOneBy(['slug' => $slug]); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
