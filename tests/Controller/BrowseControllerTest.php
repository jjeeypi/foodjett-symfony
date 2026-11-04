<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\RestaurantReview;

/** Restaurant list, restaurant page and search, rendered as pages. */
class BrowseControllerTest extends CustomerWebTestCase
{
    public function testManualOpenStatusIsUsedWhenNoOperatingHoursAreConfigured(): void
    {
        self::assertTrue($this->restaurant->getOperatingHours()->isEmpty());
        self::assertContains($this->restaurant->getName(), $this->listedRestaurants('/restaurants?open_now=1'));

        $this->fresh(Restaurant::class, (string) $this->restaurant->getId())->setOperatingStatus(Restaurant::OPERATING_CLOSED);
        $this->entityManager->flush();

        self::assertNotContains($this->restaurant->getName(), $this->listedRestaurants('/restaurants?open_now=1'));
    }

    public function testListFiltersApprovedRestaurantsAndSortsByDistance(): void
    {
        $this->addCurrentHours($this->restaurant);
        $this->addCurrentHours($this->otherRestaurant);
        $this->review($this->restaurant, 5);
        $this->review($this->otherRestaurant, 3);
        $this->entityManager->flush();

        self::assertSame(
            [$this->restaurant->getName(), $this->otherRestaurant->getName()],
            $this->listedRestaurants('/restaurants?lat=14.6000&lng=120.9850'),
        );
        self::assertSame([$this->restaurant->getName()], $this->listedRestaurants('/restaurants?rating=4&cuisine=filipino&open_now=1'));

        $this->fresh(Restaurant::class, (string) $this->otherRestaurant->getId())->setApprovalStatus(Restaurant::APPROVAL_REJECTED);
        $this->entityManager->flush();
        self::assertNotContains($this->otherRestaurant->getName(), $this->listedRestaurants('/restaurants'));
    }

    public function testListFiltersByKindAndShowsIt(): void
    {
        $this->fresh(Restaurant::class, (string) $this->otherRestaurant->getId())->setKind(Restaurant::KIND_COFFEE_TEA);
        $this->entityManager->flush();

        self::assertSame([$this->otherRestaurant->getName()], $this->listedRestaurants('/restaurants?kind=coffee_tea'));
        self::assertSame([$this->restaurant->getName()], $this->listedRestaurants('/restaurants?kind=fast_food'));
        self::assertCount(2, $this->listedRestaurants('/restaurants?kind=not-a-kind'), 'An unknown kind is ignored.');
        self::assertSelectorTextContains('main', 'Fast food');
        self::assertSelectorExists('select#kind option[value="bakery_desserts"]');
    }

    public function testPicturesShowWhenTheyExist(): void
    {
        $restaurant = $this->fresh(Restaurant::class, (string) $this->restaurant->getId())->setCoverPhotoPath('images/catalog/covers/pictured.webp');
        $item = $this->createItem($this->createCategory($restaurant, 'Pictured'), 'Pictured Dish', '99.00');
        $item->setPhotoPath('images/catalog/items/pictured.webp');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->client->request('GET', '/restaurants');
        self::assertCount(1, $crawler->filter('main li img[src="/images/catalog/covers/pictured.webp"]'));

        $crawler = $this->client->request('GET', '/restaurants/'.$this->restaurant->getSlug());
        self::assertCount(1, $crawler->filter('main img[src="/images/catalog/covers/pictured.webp"]'));
        self::assertCount(1, $crawler->filter('#item-'.$item->getId().' img[src="/images/catalog/items/pictured.webp"]'));
        self::assertSelectorTextContains('footer', 'AI-generated illustrations');
    }

    public function testRestaurantPageShowsMenuHoursAndPaginatedReviews(): void
    {
        $this->addCurrentHours($this->restaurant);
        $category = $this->createCategory($this->restaurant, 'Rice Meals');
        $item = $this->createItem($category, 'Adobo', '145.00');
        for ($i = 1; $i <= 11; ++$i) {
            $this->review($this->restaurant, 5, 'Excellent '.$i);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->client->request('GET', '/restaurants/'.$this->restaurant->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $this->restaurant->getName());
        self::assertSelectorTextContains('body', '5.0 ★ (11 reviews)');
        self::assertSelectorTextContains('section h3', 'Rice Meals');
        self::assertSelectorTextContains('#item-'.$item->getId(), 'Adobo · PHP 145.00');
        self::assertCount(1, $crawler->filter('h2:contains("Hours") + ul li'));
        self::assertCount(10, $crawler->filter('article'));

        $crawler = $this->client->request('GET', '/restaurants/'.$this->restaurant->getSlug().'?page=2');
        self::assertCount(1, $crawler->filter('article'));

        $this->fresh(Restaurant::class, (string) $this->otherRestaurant->getId())->setApprovalStatus(Restaurant::APPROVAL_PENDING);
        $this->entityManager->flush();
        $this->client->request('GET', '/restaurants/'.$this->otherRestaurant->getSlug());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSearchShowsOnlyDishesYouCanOrderNow(): void
    {
        $this->addCurrentHours($this->restaurant);
        $category = $this->createCategory($this->restaurant, 'Burgers');
        $this->createItem($category, 'Neon Burger', '180.00');
        $this->createItem($category, 'Sold Out Burger', '170.00', false);
        $this->entityManager->flush();
        $closed = $this->fresh(Restaurant::class, (string) $this->otherRestaurant->getId());
        $closed->setOperatingStatus(Restaurant::OPERATING_CLOSED);
        $this->createItem($this->createCategory($closed, 'Burgers'), 'Closed Burger', '160.00');
        $this->entityManager->flush();

        self::assertSame([$this->marker.' Neon Burger'], $this->dishes('/search?category=burgers&min_price=175&max_price=200'));
        self::assertSame([$this->marker.' Neon Burger'], $this->dishes('/search?q=Burger'));

        $crawler = $this->client->request('GET', '/search?q='.urlencode($this->restaurant->getName()));
        self::assertSame([$this->restaurant->getName()], $crawler->filter('h2:contains("Restaurants") + ul a')->each(fn ($a) => $a->text()));

        $this->client->request('GET', '/search?min_price=200&max_price=100');
        self::assertSelectorTextContains('.error', 'The minimum price cannot be higher than the maximum price.');
    }

    /** @return list<string> */
    private function listedRestaurants(string $url): array
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('main ul li > a')->each(fn ($link) => $link->text());
    }

    /** @return list<string> */
    private function dishes(string $url): array
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('h2:contains("Dishes") + ul li > a')->each(fn ($link) => $link->text());
    }

    private function review(Restaurant $restaurant, int $rating, string $comment = 'Review'): RestaurantReview
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder($this->customer, $restaurant, $this->address, Order::STATUS_DELIVERED, $now, $now);
        $review = (new RestaurantReview())->setOrder($order)->setCustomer($this->customer)->setRestaurant($restaurant)
            ->setRating($rating)->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($review);

        return $review;
    }
}
