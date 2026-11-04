<?php

namespace App\Tests\Controller;

use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Entity\OrderItem;
use App\Entity\Restaurant;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/** The restaurant's menu editor: categories, items, options and add-ons. */
class RestaurantMenuPagesTest extends RestaurantWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->restaurantUser, 'main');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.public_upload_dir'));
        parent::tearDown();
    }

    public function testCategoriesAreCreatedRenamedAndDeletedOnlyWhenEmpty(): void
    {
        $crawler = $this->page('/restaurant/menu/categories/new');
        $this->client->submit($crawler->selectButton('Save')->form(['menu_category[name]' => ' Mains ', 'menu_category[sortOrder]' => '2']));
        self::assertResponseRedirects('/restaurant/menu/categories');
        $category = $this->entityManager->getRepository(MenuCategory::class)->findOneBy(['restaurant' => $this->restaurant->getId()]);
        self::assertSame('Mains', $category->getName());

        $crawler = $this->page('/restaurant/menu/categories/'.$category->getId().'/edit');
        $this->client->submit($crawler->selectButton('Update')->form(['menu_category[name]' => 'Rice meals']));
        $this->entityManager->clear();
        self::assertSame('Rice meals', $this->entityManager->find(MenuCategory::class, $category->getId())->getName());

        $this->createItem($this->fresh(MenuCategory::class, (string) $category->getId()));
        $this->entityManager->flush();
        $this->deleteFrom('/restaurant/menu/categories', '/restaurant/menu/categories/'.$category->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'still has menu items');
    }

    public function testItemsAreCreatedWithAPhotoToggledAndEdited(): void
    {
        $category = $this->createCategory($this->restaurant, 'Mains');
        $this->entityManager->flush();
        $photo = sys_get_temp_dir().'/foodjett-dish.png';
        file_put_contents($photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $crawler = $this->page('/restaurant/menu/items/new');
        $form = $crawler->selectButton('Save')->form([
            'menu_item[menuCategory]' => (string) $category->getId(),
            'menu_item[name]' => 'Chicken Adobo',
            'menu_item[basePrice]' => '145.5',
            'menu_item[availableFrom]' => '10:00',
            'menu_item[availableUntil]' => '22:00',
        ]);
        $form['menu_item[isAvailable]']->tick();
        $form['menu_item[photo]']->upload($photo);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $item = $this->entityManager->getRepository(MenuItem::class)->findOneBy(['name' => 'Chicken Adobo']);
        self::assertSame('145.50', $item->getBasePrice());
        self::assertSame('10:00', $item->getAvailableFrom()->format('H:i'));
        self::assertStringStartsWith('uploads/restaurants/menu-items/', (string) $item->getPhotoPath());
        self::assertFileExists(self::getContainer()->getParameter('app.public_upload_dir').'/'.substr($item->getPhotoPath(), 8));

        $crawler = $this->page('/restaurant/menu/items');
        $this->client->submit($crawler->filter('#item-'.$item->getId())->selectButton('Mark unavailable')->form());
        self::assertFalse($this->reload(MenuItem::class, $item->getId())->isAvailable());

        $crawler = $this->page('/restaurant/menu/items/new');
        $this->client->submit($crawler->selectButton('Save')->form([
            'menu_item[menuCategory]' => (string) $category->getId(),
            'menu_item[name]' => 'Free?',
            'menu_item[basePrice]' => '-1',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Enter an amount of zero or more');
        @unlink($photo);
    }

    public function testItemsCannotUseAnotherRestaurantsCategoryOrBeOpenedByIt(): void
    {
        $own = $this->createCategory($this->restaurant, 'Mine');
        $foreign = $this->createCategory($this->otherRestaurant, 'Theirs');
        $foreignItem = $this->createItem($foreign, 'Not mine');
        $this->entityManager->flush();

        $crawler = $this->page('/restaurant/menu/items/new');
        self::assertCount(0, $crawler->filter('option[value="'.$foreign->getId().'"]'));
        $form = $crawler->selectButton('Save')->form(['menu_item[name]' => 'Sneaky', 'menu_item[basePrice]' => '10']);
        $form['menu_item[menuCategory]']->disableValidation()->select((string) $foreign->getId());
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(MenuItem::class)->findOneBy(['name' => 'Sneaky']));

        foreach (['/restaurant/menu/items/'.$foreignItem->getId(), '/restaurant/menu/items/'.$foreignItem->getId().'/edit',
            '/restaurant/menu/categories/'.$foreign->getId().'/edit', '/restaurant/menu/items/'.$foreignItem->getId().'/variants/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        self::assertNotNull($own->getId());

    }

    public function testAnOrderedItemIsMarkedUnavailableInsteadOfDeleted(): void
    {
        $item = $this->createItem($this->createCategory($this->restaurant));
        $order = $this->createOrder($this->restaurant);
        $now = new \DateTimeImmutable();
        $line = (new OrderItem())->setOrder($order)->setMenuItem($item)->setQuantity(1)->setUnitPrice('99.00')->setCreatedAt($now)->setUpdatedAt($now);
        $order->addItem($line);
        $this->persist($line);
        $this->entityManager->flush();

        $this->deleteFrom('/restaurant/menu/items/'.$item->getId(), '/restaurant/menu/items/'.$item->getId().'/delete');
        self::assertResponseRedirects('/restaurant/menu/items');
        $kept = $this->reload(MenuItem::class, $item->getId());
        self::assertNotNull($kept);
        self::assertFalse($kept->isAvailable());

        $unused = $this->createItem($this->fresh(MenuCategory::class, (string) $item->getMenuCategory()->getId()), 'Unused');
        $this->entityManager->flush();
        $this->deleteFrom('/restaurant/menu/items/'.$unused->getId(), '/restaurant/menu/items/'.$unused->getId().'/delete');
        self::assertNull($this->reload(MenuItem::class, $unused->getId()));
    }

    public function testOptionsAndAddonsAreManagedFromTheItemPage(): void
    {
        $item = $this->createItem($this->createCategory($this->restaurant));
        $this->entityManager->flush();
        $itemUrl = '/restaurant/menu/items/'.$item->getId();

        $crawler = $this->page($itemUrl.'/variants/new');
        $this->client->submit($crawler->selectButton('Save')->form(['menu_item_variant[name]' => 'Small', 'menu_item_variant[priceDelta]' => '-10']));
        self::assertResponseRedirects($itemUrl);
        $variant = $this->entityManager->getRepository(MenuItemVariant::class)->findOneBy(['menuItem' => $item->getId()]);
        self::assertSame('-10.00', $variant->getPriceDelta());

        $crawler = $this->page($itemUrl.'/addons/new');
        $this->client->submit($crawler->selectButton('Save')->form(['menu_item_addon[name]' => 'Egg', 'menu_item_addon[price]' => '-5']));
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($crawler->selectButton('Save')->form(['menu_item_addon[name]' => 'Egg', 'menu_item_addon[price]' => '15']));
        self::assertResponseRedirects($itemUrl);

        $crawler = $this->page($itemUrl);
        self::assertSelectorTextContains('#variants', 'Small (-10.00)');
        self::assertSelectorTextContains('#addons', 'Egg (+15.00)');

        $addon = $this->entityManager->getRepository(MenuItemAddon::class)->findOneBy(['menuItem' => $item->getId()]);
        $this->deleteFrom($itemUrl.'/addons/'.$addon->getId().'/edit', $itemUrl.'/addons/'.$addon->getId());
        $this->deleteFrom($itemUrl.'/variants/'.$variant->getId().'/edit', $itemUrl.'/variants/'.$variant->getId());
        self::assertNull($this->reload(MenuItemAddon::class, $addon->getId()));
        self::assertNull($this->reload(MenuItemVariant::class, $variant->getId()));
    }

    public function testAPendingRestaurantCannotEditTheMenu(): void
    {
        $this->fresh(Restaurant::class, (string) $this->restaurant->getId())->setApprovalStatus(Restaurant::APPROVAL_PENDING);
        $this->entityManager->flush();

        $this->client->request('GET', '/restaurant/menu/items');
        self::assertResponseStatusCodeSame(403);
    }

    private function page(string $url): Crawler
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** Submits the delete form found on $page whose action is $action. */
    private function deleteFrom(string $page, string $action): void
    {
        $this->client->submit($this->page($page)->filter('form[action="'.$action.'"]')->form());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function reload(string $class, mixed $id): ?object
    {
        $this->entityManager->clear();

        return $this->entityManager->find($class, $id);
    }
}
