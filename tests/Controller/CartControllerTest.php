<?php

namespace App\Tests\Controller;

use App\Entity\CartItem;
use App\Entity\Customer;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\User;
use App\Tests\Controller\Api\CustomerApiTestCase;

/** Cart rules: merging lines, one restaurant per cart, ownership, CSRF and roles. */
class CartControllerTest extends CustomerApiTestCase
{
    private MenuItem $meal;
    private MenuItem $otherMeal;
    private string $sauceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meal = $this->createItem($this->createCategory($this->restaurant), 'Sisig', '150.00');
        $now = new \DateTimeImmutable();
        $sauce = (new MenuItemAddon())->setMenuItem($this->meal)->setName('Egg')->setPrice('15.00')->setIsAvailable(true)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->meal->addAddon($sauce);
        $this->otherMeal = $this->createItem($this->createCategory($this->otherRestaurant), 'Ramen', '250.00');
        $this->persist($sauce);
        $this->entityManager->flush();
        $this->sauceId = (string) $sauce->getId();
        $this->client->loginUser($this->customerUser, 'main');
    }

    public function testSameChoicesMergeIntoOneLineAndTotalsUseCurrentPrices(): void
    {
        $this->add($this->meal, ['quantity' => 1, 'addons' => [$this->sauceId]]);
        $this->add($this->meal, ['quantity' => 2, 'addons' => [$this->sauceId]]);
        $this->add($this->meal, ['quantity' => 1]);

        $lines = $this->lines();
        self::assertCount(2, $lines);
        self::assertSame(3, $lines[0]->getQuantity());

        $this->client->request('GET', '/cart');
        self::assertResponseIsSuccessful();
        // 3 × (150 + 15) + 1 × 150
        self::assertSelectorTextContains('#subtotal', 'PHP 645.00');
    }

    public function testACartHoldsOneRestaurantAtATime(): void
    {
        $this->add($this->meal);
        $this->add($this->otherMeal);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Your cart has items from '.$this->restaurant->getName());
        self::assertCount(1, $this->lines());
    }

    public function testUnavailableItemsAndForeignAddonsAreRefused(): void
    {
        $this->fresh(MenuItem::class, (string) $this->otherMeal->getId())->setIsAvailable(false);
        $this->entityManager->flush();
        $this->add($this->otherMeal);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'is not available right now.');

        $this->add($this->meal, ['addons' => ['999999']]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'One of the chosen add-ons is no longer available.');
        self::assertCount(0, $this->lines());
    }

    public function testQuantityRemoveAndClear(): void
    {
        $this->add($this->meal);
        $this->add($this->meal, ['addons' => [$this->sauceId]]);
        [$first, $second] = $this->lines();

        $this->post('/cart/'.$first->getId().'/quantity', ['quantity' => 4]);
        self::assertResponseRedirects('/cart');
        self::assertSame(4, $this->lines()[0]->getQuantity());

        $this->post('/cart/'.$second->getId().'/remove');
        self::assertCount(1, $this->lines());

        $this->post('/cart/clear');
        self::assertCount(0, $this->lines());
    }

    public function testCustomersCannotTouchAnotherCustomersLines(): void
    {
        $now = new \DateTimeImmutable();
        $other = $this->fresh(Customer::class, (string) $this->otherCustomer->getId());
        $line = (new CartItem())->setMenuItem($this->entityManager->find(MenuItem::class, $this->meal->getId()))
            ->setQuantity(1)->setCreatedAt($now)->setUpdatedAt($now);
        $other->addCartItem($line);
        $this->persist($line);
        $this->entityManager->flush();

        $this->post('/cart/'.$line->getId().'/remove');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCartActionsNeedAValidToken(): void
    {
        $this->client->request('POST', '/cart/add/'.$this->meal->getId(), ['quantity' => 1]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->lines());
    }

    public function testOnlyCustomersHaveACart(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/cart');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->fresh(User::class, (string) $this->restaurant->getUser()->getId()), 'main');
        $this->client->request('GET', '/cart');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, mixed> $fields */
    private function add(MenuItem $item, array $fields = []): void
    {
        $this->post('/cart/add/'.$item->getId(), $fields + ['quantity' => 1]);
    }

    /** @param array<string, mixed> $fields */
    private function post(string $url, array $fields = []): void
    {
        // Render the menu once so the session holds a CSRF token for the "cart" form.
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', '/restaurants/'.$this->restaurant->getSlug());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, $fields + ['_token' => $token]);
    }

    /** @return list<CartItem> */
    private function lines(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(CartItem::class)->findBy(
            ['customer' => $this->customer->getId()],
            ['id' => 'ASC'],
        );
    }
}
