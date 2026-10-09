<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RestaurantReview;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;

final class CustomerBrowsingControllerTest extends CustomerApiTestCase
{
    public function testRestaurantIndexFiltersApprovedRestaurantsAndSortsByDistance(): void
    {
        $this->addCurrentHours($this->restaurant);
        $this->addCurrentHours($this->otherRestaurant);
        $this->review($this->restaurant, 5);
        $this->review($this->otherRestaurant, 3);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurants?lat=14.6000&lng=120.9850', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $this->restaurant->getId(), (string) $this->otherRestaurant->getId()], array_column($this->payload()['data'], 'id'));

        $this->client->request('GET', '/api/restaurants?rating=4&cuisine_type=filipino&open_now=1', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $this->restaurant->getId()], array_column($this->payload()['data'], 'id'));

        /** @var \App\Entity\Restaurant $other */
        $other = $this->fresh(\App\Entity\Restaurant::class, (string) $this->otherRestaurant->getId());
        $other->setApprovalStatus(ApprovalStatus::REJECTED);
        $this->entityManager->flush();
        $this->client->request('GET', '/api/restaurants', server: $this->auth());
        self::assertNotContains((string) $other->getId(), array_column($this->payload()['data'], 'id'));
    }

    public function testRestaurantDetailGroupsMenuAndReviewsArePaginated(): void
    {
        $this->addCurrentHours($this->restaurant);
        $category = $this->createCategory($this->restaurant, 'Rice Meals');
        $item = $this->createItem($category, 'Adobo', '145.00');
        $review = $this->review($this->restaurant, 5, 'Excellent');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurants/'.$this->restaurant->getId(), server: $this->auth());
        self::assertResponseIsSuccessful();
        $restaurant = $this->payload()['restaurant'];
        self::assertSame(5, $restaurant['rating']);
        self::assertSame(1, $restaurant['review_count']);
        self::assertSame((string) $category->getId(), (string) $restaurant['menu_categories'][0]['id']);
        self::assertSame((string) $item->getId(), (string) $restaurant['menu_categories'][0]['items'][0]['id']);
        self::assertCount(1, $restaurant['operating_hours']);

        $this->client->request('GET', '/api/restaurants/'.$this->restaurant->getId().'/reviews?perPage=1', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $review->getId()], array_column($this->payload()['data'], 'id'));
        self::assertSame(1, $this->payload()['meta']['total']);

        /** @var \App\Entity\Restaurant $other */
        $other = $this->fresh(\App\Entity\Restaurant::class, (string) $this->otherRestaurant->getId());
        $other->setApprovalStatus(ApprovalStatus::PENDING);
        $this->entityManager->flush();
        $this->client->request('GET', '/api/restaurants/'.$other->getId(), server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testFoodBrowseAndCombinedSearchEnforceAvailabilityAndOpenHours(): void
    {
        $this->addCurrentHours($this->restaurant);
        $category = $this->createCategory($this->restaurant, 'Burgers');
        $available = $this->createItem($category, 'Neon Burger', '180.00');
        $this->createItem($category, 'Sold Out Burger', '170.00', false);
        $foreignCategory = $this->createCategory($this->otherRestaurant, 'Burgers');
        $this->createItem($foreignCategory, 'Closed Burger', '160.00');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/menu-items/search?category=burgers&min_price=175&max_price=200', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $available->getId()], array_column($this->payload()['data'], 'id'));

        $this->client->request('GET', '/api/search?q=Neon', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $available->getId()], array_column($this->payload()['dishes'], 'id'));

        $this->client->request('GET', '/api/search?q=Test%20Kitchen', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $this->restaurant->getId()], array_column($this->payload()['restaurants'], 'id'));

        $this->client->request('GET', '/api/menu-items/search?min_price=200&max_price=100', server: $this->auth());
        self::assertResponseStatusCodeSame(422);
    }

    private function review(\App\Entity\Restaurant $restaurant, int $rating, string $comment = 'Review'): RestaurantReview
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder($this->customer, $restaurant, $this->address, OrderStatus::DELIVERED, $now, $now);
        $review = (new RestaurantReview())->setOrder($order)->setCustomer($this->customer)->setRestaurant($restaurant)
            ->setRating($rating)->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($review);

        return $review;
    }
}
