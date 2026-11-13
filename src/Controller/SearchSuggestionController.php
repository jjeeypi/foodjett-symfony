<?php

namespace App\Controller;

use App\Entity\MenuItem;
use App\Entity\Restaurant;
use App\Repository\MenuItemRepository;
use App\Repository\RestaurantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a search box offers while the customer types: a few restaurants and dishes they can order now,
 * ranked the same way as the search page, plus a link to every result.
 */
final class SearchSuggestionController extends AbstractController
{
    #[Route('/search/suggestion', name: 'app_search_suggestion', methods: ['GET'])]
    public function index(Request $request, RestaurantRepository $restaurants, MenuItemRepository $menuItems): JsonResponse
    {
        $query = trim($request->query->getString('q'));
        $terms = SearchController::terms($query);
        if (mb_strlen($query) < 2 || [] === $terms) {
            return $this->json(['restaurants' => [], 'dishes' => [], 'all' => null]);
        }

        $restaurantRows = array_map(fn (Restaurant $restaurant): array => [
            'name' => $restaurant->getName(),
            'detail' => implode(' · ', array_filter([$restaurant->getKindLabel(), $restaurant->getCuisineType()])),
            'url' => $this->generateUrl('app_restaurant_menu', ['slug' => $restaurant->getSlug()]),
        ], $restaurants->search($terms, 4));

        $dishes = array_slice(SearchController::orderableNow($menuItems->search($terms, limit: 60)), 0, 6);
        $dishRows = array_map(function (MenuItem $item): array {
            $restaurant = $item->getMenuCategory()->getRestaurant();

            return [
                'name' => $item->getName(),
                'detail' => '₱'.$item->getBasePrice().' · '.$restaurant->getName(),
                'url' => $this->generateUrl('app_restaurant_menu', ['slug' => $restaurant->getSlug()]).'#item-'.$item->getId(),
            ];
        }, $dishes);

        return $this->json([
            'restaurants' => $restaurantRows,
            'dishes' => $dishRows,
            'all' => $this->generateUrl('app_search', ['q' => $query]),
        ]);
    }
}
