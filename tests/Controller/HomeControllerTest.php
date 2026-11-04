<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;

/** The explore page: welcome, craving shortcuts and the restaurant cards. */
class HomeControllerTest extends CustomerWebTestCase
{
    public function testGuestsSeeTheExplorePageWithCravings(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Good food.');
        self::assertCount(count(Restaurant::CRAVINGS) + 1, $crawler->filter('.cravings .craving'));
        self::assertSelectorExists('.restaurant-card');
        self::assertSelectorNotExists('form.favorite-form');
        self::assertSelectorExists('a[href="/register/restaurant"]');
    }

    public function testACravingNarrowsTheRestaurants(): void
    {
        // fresh() clears the entity manager, so each change is saved before the next lookup.
        $this->fresh(Restaurant::class, (string) $this->restaurant->getId())->setCuisineType('Wood-fired Pizza');
        $this->entityManager->flush();
        $this->fresh(Restaurant::class, (string) $this->otherRestaurant->getId())->setCuisineType('Milk Tea');
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->client->loginUser($this->customerUser, 'main');

        $crawler = $this->client->request('GET', '/?craving=pizza');
        $names = $crawler->filter('.restaurant-card h3')->each(fn ($h) => $h->text());
        self::assertContains($this->restaurant->getName(), $names);
        self::assertNotContains($this->otherRestaurant->getName(), $names);
        self::assertSelectorTextContains('.craving.selected', 'Pizza');
        self::assertSelectorExists('form.favorite-form');

        $crawler = $this->client->request('GET', '/?craving=not-a-craving');
        self::assertSelectorTextContains('.craving.selected', 'All');
    }
}
