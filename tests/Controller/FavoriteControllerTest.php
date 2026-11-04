<?php

namespace App\Tests\Controller;

use App\Entity\Favorite;

/** Saving restaurants with the heart button and listing them on the favorites page. */
class FavoriteControllerTest extends CustomerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->customerUser, 'main');
    }

    public function testTheHeartSavesAndRemovesARestaurant(): void
    {
        $crawler = $this->client->request('GET', '/favorites');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty', 'No favorites yet');

        $crawler = $this->client->request('GET', '/restaurants');
        $heart = $crawler->filter('#restaurant-'.$this->restaurant->getId().' form.favorite-form');
        self::assertCount(1, $heart);
        $this->client->submit($heart->form());
        self::assertResponseRedirects('/restaurants#restaurant-'.$this->restaurant->getId());
        self::assertCount(1, $this->favorites());

        $crawler = $this->client->request('GET', '/favorites');
        self::assertSame([$this->restaurant->getName()], $crawler->filter('.restaurant-card h3')->each(fn ($h) => $h->text()));
        self::assertSame('true', $crawler->filter('.favorite')->attr('aria-pressed'));

        $this->client->submit($crawler->filter('form.favorite-form')->form());
        self::assertCount(0, $this->favorites());
    }

    public function testABadTokenOrAnUnknownRestaurantSavesNothing(): void
    {
        $this->client->request('POST', '/favorites/'.$this->restaurant->getSlug(), ['_token' => 'wrong']);
        self::assertResponseRedirects();
        self::assertCount(0, $this->favorites());

        $this->client->request('POST', '/favorites/no-such-place', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOffSiteRedirectsAreIgnored(): void
    {
        $crawler = $this->client->request('GET', '/restaurants');
        $form = $crawler->filter('#restaurant-'.$this->restaurant->getId().' form.favorite-form')->form(['back' => '//evil.example/']);
        $this->client->submit($form);

        self::assertResponseRedirects('/restaurants/'.$this->restaurant->getSlug());
    }

    public function testOnlyCustomersHaveFavorites(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/favorites');
        self::assertResponseRedirects('/login');
    }

    /** @return list<Favorite> */
    private function favorites(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Favorite::class)->findBy(['customer' => $this->customer->getId()]);
    }
}
