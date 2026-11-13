<?php

namespace App\Repository;

use App\Entity\MenuItem;
use App\Entity\Restaurant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MenuItem>
 */
class MenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItem::class);
    }

    /**
     * Available dishes of approved restaurants for the search engine. Every term must appear in the dish name or
     * description, its category, or the restaurant's name or cuisine. With terms, the best match comes first:
     * the words typed together in the dish name, then a word of the name starting with a term, then a name
     * containing it, then category, restaurant and description matches. Category and price filters narrow it.
     *
     * @param list<string> $terms lowercase letters and digits only, from SearchController::terms()
     *
     * @return list<MenuItem>
     */
    public function search(array $terms, string $category = '', ?string $minimum = null, ?string $maximum = null, string $sort = 'relevance', int $limit = 500): array
    {
        $query = $this->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->innerJoin('category.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('item.isAvailable = true')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->setMaxResults($limit);

        $score = [];
        foreach (array_values($terms) as $i => $term) {
            $query->andWhere("(LOWER(item.name) LIKE :any$i OR LOWER(item.description) LIKE :any$i OR LOWER(category.name) LIKE :any$i OR LOWER(restaurant.name) LIKE :any$i OR LOWER(restaurant.cuisineType) LIKE :any$i)")
                ->setParameter("any$i", '%'.$term.'%')->setParameter("start$i", $term.'%')->setParameter("word$i", '% '.$term.'%');
            $score[] = "CASE WHEN LOWER(item.name) LIKE :start$i OR LOWER(item.name) LIKE :word$i THEN 30 WHEN LOWER(item.name) LIKE :any$i THEN 15 ELSE 0 END";
            $score[] = "CASE WHEN LOWER(category.name) LIKE :any$i THEN 8 ELSE 0 END";
            $score[] = "CASE WHEN LOWER(restaurant.name) LIKE :any$i OR LOWER(restaurant.cuisineType) LIKE :any$i THEN 5 ELSE 0 END";
            $score[] = "CASE WHEN LOWER(item.description) LIKE :any$i THEN 2 ELSE 0 END";
        }
        if (count($terms) > 1) {
            $score[] = 'CASE WHEN LOWER(item.name) LIKE :phrase THEN 50 ELSE 0 END';
            $query->setParameter('phrase', '%'.implode(' ', $terms).'%');
        }
        if ([] !== $score) {
            $query->addSelect('('.implode(' + ', $score).') AS HIDDEN relevance');
        }

        if ('' !== $category) {
            $query->andWhere('LOWER(category.name) LIKE :category')->setParameter('category', '%'.$category.'%');
        }
        if (null !== $minimum) {
            $query->andWhere('item.basePrice >= :minimum')->setParameter('minimum', $minimum);
        }
        if (null !== $maximum) {
            $query->andWhere('item.basePrice <= :maximum')->setParameter('maximum', $maximum);
        }

        match ($sort) {
            'price_asc' => $query->orderBy('item.basePrice', 'ASC'),
            'price_desc' => $query->orderBy('item.basePrice', 'DESC'),
            default => [] !== $score ? $query->orderBy('relevance', 'DESC') : $query,
        };

        return $query->addOrderBy('item.name', 'ASC')->getQuery()->getResult();
    }

    /**
     * Dish and category names on the approved menus: the words "did you mean" can offer.
     *
     * @return list<string>
     */
    public function findSearchWords(): array
    {
        $rows = $this->createQueryBuilder('item')
            ->select('item.name AS name', 'category.name AS categoryName')
            ->innerJoin('item.menuCategory', 'category')
            ->innerJoin('category.restaurant', 'restaurant')
            ->andWhere('item.isAvailable = true')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', Restaurant::APPROVAL_APPROVED)
            ->getQuery()->getArrayResult();

        return array_values(array_unique([...array_column($rows, 'name'), ...array_column($rows, 'categoryName')]));
    }

    /** The restaurant's menu items in menu order. */
    public function createForRestaurantQuery(Restaurant $restaurant): QueryBuilder
    {
        return $this->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('category.sortOrder', 'ASC')->addOrderBy('item.name', 'ASC');
    }

    public function findOneForRestaurant(int|string $id, Restaurant $restaurant): ?MenuItem
    {
        return $this->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->andWhere('item.id = :id')->setParameter('id', $id)
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->getQuery()->getOneOrNullResult();
    }
}
