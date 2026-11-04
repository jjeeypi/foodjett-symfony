<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\DeliveryZoneRepository;
use App\Repository\FavoriteRepository;
use App\Repository\RestaurantRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The public restaurant list, with craving, kind, cuisine, rating and open-now filters. */
final class BrowseController extends AbstractController
{
    #[Route('/restaurants', name: 'app_restaurants', methods: ['GET'])]
    public function index(Request $request, RestaurantRepository $restaurants, FavoriteRepository $favorites, PaginatorInterface $paginator): Response
    {
        $kind = $request->query->getString('kind');
        $kind = array_key_exists($kind, Restaurant::KINDS) ? $kind : '';
        $craving = $request->query->getString('craving');
        $craving = isset(Restaurant::CRAVINGS[$craving]) ? $craving : '';
        $cuisine = mb_strtolower(trim($request->query->getString('cuisine')));
        $rating = $request->query->getString('rating');
        $minimumRating = is_numeric($rating) ? max(0.0, min(5.0, (float) $rating)) : null;
        $openOnly = $request->query->getBoolean('open_now');
        [$latitude, $longitude] = $this->coordinates($request);

        $rows = [];
        foreach ($restaurants->findApproved() as $restaurant) {
            if ('' !== $craving && !$restaurant->matchesCraving($craving)) {
                continue;
            }
            if ('' !== $kind && $restaurant->getKind() !== $kind) {
                continue;
            }
            if ('' !== $cuisine && !str_contains(mb_strtolower((string) $restaurant->getCuisineType()), $cuisine)) {
                continue;
            }
            if (null !== $minimumRating && $restaurant->getAverageRating() < $minimumRating) {
                continue;
            }
            if ($openOnly && !$restaurant->isOpenNow()) {
                continue;
            }
            $distance = null === $latitude ? null : DeliveryZoneRepository::distanceKm(
                $latitude, $longitude, (float) $restaurant->getLatitude(), (float) $restaurant->getLongitude(),
            );
            $rows[] = ['restaurant' => $restaurant, 'distance' => $distance];
        }

        // Nearest first when the visitor shared a location, otherwise best rated first.
        usort($rows, static fn (array $a, array $b): int => (null !== $latitude
            ? $a['distance'] <=> $b['distance']
            : $b['restaurant']->getAverageRating() <=> $a['restaurant']->getAverageRating())
            ?: strcmp($a['restaurant']->getName(), $b['restaurant']->getName()));

        return $this->render('browse/index.html.twig', [
            'pagination' => $paginator->paginate($rows, $request->query->getInt('page', 1), 20),
            'favoriteIds' => $favorites->findRestaurantIdsFor($this->getUser() instanceof User ? $this->getUser()->getCustomer() : null),
            'kinds' => Restaurant::KINDS,
            'cravings' => Restaurant::CRAVINGS,
            'filters' => ['craving' => $craving, 'kind' => $kind, 'cuisine' => $cuisine, 'rating' => $rating, 'open_now' => $openOnly],
        ]);
    }

    /** @return array{0: ?float, 1: ?float} */
    private function coordinates(Request $request): array
    {
        $latitude = $request->query->get('lat');
        $longitude = $request->query->get('lng');
        if (!is_numeric($latitude) || !is_numeric($longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return [null, null];
        }

        return [(float) $latitude, (float) $longitude];
    }
}
