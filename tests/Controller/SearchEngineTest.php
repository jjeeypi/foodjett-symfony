<?php

namespace App\Tests\Controller;

/** The search engine: every word must match, best match first, sorting, typo correction, highlights and live suggestions. */
class SearchEngineTest extends CustomerWebTestCase
{
    public function testEveryWordMustMatchAndTheBestMatchComesFirst(): void
    {
        $this->menu();

        self::assertSame(['Zestara Bowl', 'Plain Bowl'], $this->dishes('/search?q=zestara+bowl'), 'Both words in the name beat one word in the description.');
        self::assertSame(['Zestara Bowl', 'Zestara Noodles', 'Plain Bowl'], $this->dishes('/search?q=Zestara'), 'A name match beats a description match.');
        self::assertSame(['Plain Bowl', 'Zestara Noodles', 'Zestara Bowl'], $this->dishes('/search?q=zestara&sort=price_asc'));
        self::assertSame(['Zestara Bowl', 'Zestara Noodles', 'Plain Bowl'], $this->dishes('/search?q=the+zestara+with'), 'Small words are ignored.');
        self::assertSame([], $this->dishes('/search?q=zestara+pizza'), 'Every word has to match.');
        self::assertSame(['Zestara Noodles'], $this->dishes('/search?q=zestara+'.urlencode($this->restaurant->getCuisineType()).'&category=specials&min_price=100&max_price=130'));

        $this->client->request('GET', '/search?q=zestara');
        self::assertSelectorTextContains('#result-summary', '3 dishes');
        self::assertSelectorTextContains('.dishes mark', 'Zestara', 'The searched word is highlighted.');
    }

    public function testAMisspelledSearchOffersTheClosestMenuWord(): void
    {
        $this->menu();

        $crawler = $this->client->request('GET', '/search?q=zestera');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#did-you-mean', 'zestara');
        self::assertSame('/search?q=zestara', $crawler->filter('#did-you-mean a')->attr('href'));

        $this->client->request('GET', '/search?q=specals+zestara');
        self::assertSelectorTextContains('#did-you-mean', 'specials zestara', 'Category names count as menu words too.');

        $this->client->request('GET', '/search?q=zestara');
        self::assertSelectorNotExists('#did-you-mean');
    }

    public function testTheSearchBoxSuggestsRestaurantsAndDishesWhileTyping(): void
    {
        $this->menu();

        $this->client->request('GET', '/search/suggestion?q=zesta');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($this->marker.' Zestara Bowl', $data['dishes'][0]['name']);
        self::assertStringContainsString('#item-', $data['dishes'][0]['url']);
        self::assertSame('/search?q=zesta', $data['all']);

        $this->client->request('GET', '/search/suggestion?q='.urlencode($this->restaurant->getName()));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([$this->restaurant->getName()], array_column($data['restaurants'], 'name'));

        $this->client->request('GET', '/search/suggestion?q=z');
        self::assertSame(['restaurants' => [], 'dishes' => [], 'all' => null], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    private function menu(): void
    {
        $this->addCurrentHours($this->restaurant);
        $specials = $this->createCategory($this->restaurant, 'Specials');
        $this->createItem($specials, 'Plain Bowl', '90.00')->setDescription('Rice topped with zestara sauce');
        $this->createItem($specials, 'Zestara Bowl', '150.00');
        $this->createItem($specials, 'Zestara Noodles', '120.00');
        $this->entityManager->flush();
    }

    /** The dish names on a search page, without the test marker, ignoring dishes this test did not create. */
    private function dishes(string $url): array
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $names = $crawler->filter('h2:contains("Dishes") + ul li a')->each(fn ($link) => $link->text());

        return array_values(array_map(
            fn (string $name): string => substr($name, strlen($this->marker) + 1),
            array_filter($names, fn (string $name): bool => str_starts_with($name, $this->marker)),
        ));
    }
}
