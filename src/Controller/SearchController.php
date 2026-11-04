<?php

namespace App\Controller;

use App\Repository\MenuItemRepository;
use App\Repository\RestaurantRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Search across restaurants (name or cuisine) and dishes you can order right now
 * (name or description), with optional category and price filters for dishes.
 */
final class SearchController extends AbstractController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function index(
        Request $request,
        RestaurantRepository $restaurants,
        MenuItemRepository $menuItems,
        PaginatorInterface $paginator,
    ): Response {
        $query = trim($request->query->getString('q'));
        $needle = mb_strtolower($query);
        $category = mb_strtolower(trim($request->query->getString('category')));
        $minimum = $this->price($request->query->getString('min_price'));
        $maximum = $this->price($request->query->getString('max_price'));
        $errors = [];
        if (null !== $minimum && null !== $maximum && 1 === bccomp($minimum, $maximum, 2)) {
            $errors[] = 'The minimum price cannot be higher than the maximum price.';
        }

        $matchingRestaurants = [];
        $dishes = [];
        $searched = '' !== $needle || '' !== $category || null !== $minimum || null !== $maximum;
        if ($searched && [] === $errors) {
            if ('' !== $needle) {
                foreach ($restaurants->findApproved() as $restaurant) {
                    if (str_contains(mb_strtolower($restaurant->getName()), $needle)
                        || str_contains(mb_strtolower((string) $restaurant->getCuisineType()), $needle)) {
                        $matchingRestaurants[] = $restaurant;
                    }
                }
            }

            $now = new \DateTimeImmutable();
            foreach ($menuItems->findAvailableFromApprovedRestaurants() as $item) {
                if (!$item->getMenuCategory()->getRestaurant()->isOpenAt($now) || !$item->isOfferedAt($now)) {
                    continue;
                }
                if ('' !== $category && !str_contains(mb_strtolower($item->getMenuCategory()->getName()), $category)) {
                    continue;
                }
                $price = $item->getBasePrice();
                if ((null !== $minimum && -1 === bccomp($price, $minimum, 2)) || (null !== $maximum && 1 === bccomp($price, $maximum, 2))) {
                    continue;
                }
                if ('' !== $needle && !str_contains(mb_strtolower($item->getName()), $needle)
                    && !str_contains(mb_strtolower((string) $item->getDescription()), $needle)) {
                    continue;
                }
                $dishes[] = $item;
            }
            usort($dishes, static fn ($a, $b): int => strcmp($a->getName(), $b->getName()));
        }

        return $this->render('search/index.html.twig', [
            'query' => $query,
            'filters' => [
                'category' => $request->query->getString('category'),
                'min_price' => $request->query->getString('min_price'),
                'max_price' => $request->query->getString('max_price'),
            ],
            'errors' => $errors,
            'searched' => $searched,
            'restaurants' => array_slice($matchingRestaurants, 0, 20),
            'dishes' => $paginator->paginate($dishes, $request->query->getInt('page', 1), 20),
        ]);
    }

    /** A non-negative price rounded to centavos, or null when blank or not a plain number. */
    private function price(string $value): ?string
    {
        $value = trim($value);

        return 1 === preg_match('/^\d{1,8}(\.\d+)?$/', $value) ? bcround($value, 2) : null;
    }
}
