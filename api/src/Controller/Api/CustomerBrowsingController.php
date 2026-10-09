<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\MenuItem;
use App\Entity\Restaurant;
use App\Entity\RestaurantReview;
use App\Enum\ApprovalStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Service\ApiPaginator;
use App\Service\DeliveryZoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CUSTOMER')]
final class CustomerBrowsingController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator)
    {
    }

    #[Route('/api/restaurants', name: 'api_customer_restaurants_index', methods: ['GET'])]
    public function restaurants(Request $request): JsonResponse
    {
        $restaurants = $this->approvedRestaurants();
        $cuisine = mb_strtolower(trim($request->query->getString('cuisine_type')));
        $minimumRating = $request->query->has('rating') ? $request->query->get('rating') : null;
        if (null !== $minimumRating && (!is_numeric($minimumRating) || (float) $minimumRating < 0 || (float) $minimumRating > 5)) {
            return $this->json(['message' => 'rating must be between 0 and 5.'], 422);
        }
        $openOnly = filter_var($request->query->get('open_now', false), FILTER_VALIDATE_BOOL);
        $coordinates = $this->coordinates($request);
        if ($coordinates instanceof JsonResponse) {
            return $coordinates;
        }
        [$latitude, $longitude] = $coordinates;
        $rows = [];
        foreach ($restaurants as $restaurant) {
            $rating = $this->rating($restaurant);
            $open = $this->isOpen($restaurant);
            if ('' !== $cuisine && !str_contains(mb_strtolower((string) $restaurant->getCuisineType()), $cuisine)) {
                continue;
            }
            if (null !== $minimumRating && $rating['average'] < (float) $minimumRating) {
                continue;
            }
            if ($openOnly && !$open) {
                continue;
            }
            $distance = null !== $latitude && null !== $longitude ? DeliveryZoneService::distanceKm($latitude, $longitude, (float) $restaurant->getLatitude(), (float) $restaurant->getLongitude()) : null;
            $rows[] = $this->restaurantSummary($restaurant, $rating, $open, $distance);
        }
        usort($rows, static function (array $left, array $right) use ($latitude): int {
            if (null !== $latitude) {
                return ($left['distance_km'] <=> $right['distance_km']) ?: strcmp($left['name'], $right['name']);
            }

            return ($right['rating'] <=> $left['rating']) ?: strcmp($left['name'], $right['name']);
        });

        return $this->json($this->paginateArray($rows, $request));
    }

    #[Route('/api/restaurants/{id}', name: 'api_customer_restaurants_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function restaurant(string $id): JsonResponse
    {
        $restaurant = $this->approvedRestaurant($id);
        $categories = [];
        foreach ($restaurant->getMenuCategories() as $category) {
            $items = [];
            foreach ($category->getItems() as $item) {
                $items[] = $this->menuItem($item);
            }
            $categories[] = ['id' => $category->getId(), 'name' => $category->getName(), 'sort_order' => $category->getSortOrder(), 'items' => $items];
        }
        $hours = array_map(static fn ($hour): array => [
            'day_of_week' => $hour->getDayOfWeek(), 'opens_at' => $hour->getOpensAt()->format('H:i'), 'closes_at' => $hour->getClosesAt()->format('H:i'),
        ], $restaurant->getOperatingHours()->toArray());
        $rating = $this->rating($restaurant);

        return $this->json(['restaurant' => $this->restaurantSummary($restaurant, $rating, $this->isOpen($restaurant), null) + [
            'description' => $restaurant->getDescription(), 'cover_photo_path' => $restaurant->getCoverPhotoPath(),
            'address' => $restaurant->getAddress(), 'latitude' => $restaurant->getLatitude(), 'longitude' => $restaurant->getLongitude(),
            'default_prep_time_minutes' => $restaurant->getDefaultPrepTimeMinutes(), 'min_order_amount' => $restaurant->getMinOrderAmount(),
            'operating_hours' => $hours, 'menu_categories' => $categories,
        ]]);
    }

    #[Route('/api/restaurants/{id}/reviews', name: 'api_customer_restaurants_reviews', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function reviews(string $id, Request $request): JsonResponse
    {
        $restaurant = $this->approvedRestaurant($id);
        $query = $this->entityManager->getRepository(RestaurantReview::class)->createQueryBuilder('review')
            ->andWhere('review.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('review.createdAt', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, static fn (RestaurantReview $review): array => [
            'id' => $review->getId(), 'rating' => $review->getRating(), 'comment' => $review->getComment(),
            'photo_path' => $review->getPhotoPath(), 'customer_name' => $review->getCustomer()->getUser()->getName(),
            'restaurant_reply' => $review->getRestaurantReply(), 'replied_at' => $review->getRepliedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $review->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ]));
    }

    #[Route('/api/menu-items/search', name: 'api_customer_menu_items_search', methods: ['GET'])]
    public function menuItems(Request $request): JsonResponse
    {
        $rows = $this->availableMenuRows($request);
        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        return $this->json($this->paginateArray($rows, $request));
    }

    #[Route('/api/search', name: 'api_customer_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = mb_strtolower(trim($request->query->getString('q')));
        if ('' === $query) {
            return $this->json(['message' => 'q is required.'], 422);
        }
        $restaurants = [];
        foreach ($this->approvedRestaurants() as $restaurant) {
            if (str_contains(mb_strtolower($restaurant->getName()), $query) || str_contains(mb_strtolower((string) $restaurant->getCuisineType()), $query)) {
                $restaurants[] = $this->restaurantSummary($restaurant, $this->rating($restaurant), $this->isOpen($restaurant), null);
            }
        }
        $dishRequest = $request->duplicate(query: ['q' => $query, 'perPage' => 100]);
        $dishes = $this->availableMenuRows($dishRequest);
        if ($dishes instanceof JsonResponse) {
            return $dishes;
        }

        return $this->json(['query' => $request->query->getString('q'), 'restaurants' => array_slice($restaurants, 0, 20), 'dishes' => array_slice($dishes, 0, 20)]);
    }

    /** @return list<Restaurant> */
    private function approvedRestaurants(): array
    {
        return $this->entityManager->getRepository(Restaurant::class)->createQueryBuilder('restaurant')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->orderBy('restaurant.name', 'ASC')->getQuery()->getResult();
    }

    private function approvedRestaurant(string $id): Restaurant
    {
        $restaurant = $this->entityManager->getRepository(Restaurant::class)->createQueryBuilder('restaurant')
            ->andWhere('restaurant.id = :id')->setParameter('id', $id)
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->getQuery()->getOneOrNullResult();
        if (!$restaurant instanceof Restaurant) {
            throw $this->createNotFoundException('Restaurant not found.');
        }

        return $restaurant;
    }

    /** @return list<array<string, mixed>>|JsonResponse */
    private function availableMenuRows(Request $request): array|JsonResponse
    {
        $minimum = $request->query->get('min_price');
        $maximum = $request->query->get('max_price');
        if ((null !== $minimum && (!is_numeric($minimum) || (float) $minimum < 0)) || (null !== $maximum && (!is_numeric($maximum) || (float) $maximum < 0))) {
            return $this->json(['message' => 'Price filters must be non-negative numbers.'], 422);
        }
        if (null !== $minimum && null !== $maximum && (float) $minimum > (float) $maximum) {
            return $this->json(['message' => 'min_price cannot exceed max_price.'], 422);
        }
        $category = mb_strtolower(trim($request->query->getString('category')));
        $search = mb_strtolower(trim($request->query->getString('q')));
        /** @var list<MenuItem> $items */
        $items = $this->entityManager->getRepository(MenuItem::class)->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->innerJoin('category.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('item.isAvailable = true')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->getQuery()->getResult();
        $rows = [];
        foreach ($items as $item) {
            $restaurant = $item->getMenuCategory()->getRestaurant();
            if (!$this->isOpen($restaurant) || !$this->withinItemWindow($item)) {
                continue;
            }
            if ('' !== $category && !str_contains(mb_strtolower($item->getMenuCategory()->getName()), $category) && (string) $item->getMenuCategory()->getId() !== $category) {
                continue;
            }
            $price = (float) $item->getBasePrice();
            if ((null !== $minimum && $price < (float) $minimum) || (null !== $maximum && $price > (float) $maximum)) {
                continue;
            }
            if ('' !== $search && !str_contains(mb_strtolower($item->getName()), $search) && !str_contains(mb_strtolower((string) $item->getDescription()), $search)) {
                continue;
            }
            $rows[] = $this->menuItem($item) + ['restaurant' => ['id' => $restaurant->getId(), 'name' => $restaurant->getName()]];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $rows;
    }

    /** @return array{average: float, count: int} */
    private function rating(Restaurant $restaurant): array
    {
        $count = $restaurant->getReviews()->count();
        if (0 === $count) {
            return ['average' => 0.0, 'count' => 0];
        }
        $sum = 0;
        foreach ($restaurant->getReviews() as $review) {
            $sum += $review->getRating();
        }

        return ['average' => round($sum / $count, 2), 'count' => $count];
    }

    private function isOpen(Restaurant $restaurant, ?\DateTimeImmutable $now = null): bool
    {
        if (RestaurantOperatingStatus::OPEN !== $restaurant->getOperatingStatus()) {
            return false;
        }
        $now ??= new \DateTimeImmutable();
        $today = (int) $now->format('w');
        $previousDay = (6 + $today) % 7;
        $time = $now->format('H:i:s');
        foreach ($restaurant->getOperatingHours() as $hours) {
            $opens = $hours->getOpensAt()->format('H:i:s');
            $closes = $hours->getClosesAt()->format('H:i:s');
            $overnight = $opens > $closes;
            if ($hours->getDayOfWeek() === $today && ((!$overnight && $time >= $opens && $time <= $closes) || ($overnight && $time >= $opens))) {
                return true;
            }
            if ($overnight && $hours->getDayOfWeek() === $previousDay && $time <= $closes) {
                return true;
            }
        }

        return false;
    }

    private function withinItemWindow(MenuItem $item): bool
    {
        $from = $item->getAvailableFrom()?->format('H:i:s');
        $until = $item->getAvailableUntil()?->format('H:i:s');
        if (null === $from && null === $until) {
            return true;
        }
        $time = (new \DateTimeImmutable())->format('H:i:s');
        if (null !== $from && null !== $until && $from > $until) {
            return $time >= $from || $time <= $until;
        }

        return (null === $from || $time >= $from) && (null === $until || $time <= $until);
    }

    /** @return array{0: ?float, 1: ?float}|JsonResponse */
    private function coordinates(Request $request): array|JsonResponse
    {
        $latitude = $request->query->get('lat');
        $longitude = $request->query->get('lng');
        if (null === $latitude && null === $longitude) {
            return [null, null];
        }
        if (!is_numeric($latitude) || !is_numeric($longitude) || (float) $latitude < -90 || (float) $latitude > 90 || (float) $longitude < -180 || (float) $longitude > 180) {
            return $this->json(['message' => 'lat and lng must be valid coordinates.'], 422);
        }

        return [(float) $latitude, (float) $longitude];
    }

    /** @param array{average: float, count: int} $rating
     * @return array<string, mixed>
     */
    private function restaurantSummary(Restaurant $restaurant, array $rating, bool $open, ?float $distance): array
    {
        return [
            'id' => $restaurant->getId(), 'name' => $restaurant->getName(), 'slug' => $restaurant->getSlug(),
            'logo_path' => $restaurant->getLogoPath(), 'cuisine_type' => $restaurant->getCuisineType(),
            'operating_status' => $restaurant->getOperatingStatus()->value, 'is_open_now' => $open,
            'rating' => $rating['average'], 'review_count' => $rating['count'],
            'distance_km' => null === $distance ? null : round($distance, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function menuItem(MenuItem $item): array
    {
        return [
            'id' => $item->getId(), 'name' => $item->getName(), 'description' => $item->getDescription(),
            'photo_path' => $item->getPhotoPath(), 'base_price' => $item->getBasePrice(), 'is_available' => $item->isAvailable(),
            'available_from' => $item->getAvailableFrom()?->format('H:i'), 'available_until' => $item->getAvailableUntil()?->format('H:i'),
            'is_featured' => $item->isFeatured(), 'category' => ['id' => $item->getMenuCategory()->getId(), 'name' => $item->getMenuCategory()->getName()],
            'variants' => array_map(static fn ($variant): array => ['id' => $variant->getId(), 'name' => $variant->getName(), 'price_delta' => $variant->getPriceDelta()], $item->getVariants()->toArray()),
            'addons' => array_map(static fn ($addon): array => ['id' => $addon->getId(), 'name' => $addon->getName(), 'price' => $addon->getPrice(), 'is_available' => $addon->isAvailable()], $item->getAddons()->toArray()),
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, perPage: int, total: int, lastPage: int}}
     */
    private function paginateArray(array $rows, Request $request): array
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('perPage', 25)));
        $total = count($rows);

        return ['data' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)), 'meta' => [
            'page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]];
    }
}
