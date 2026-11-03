<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;

final class RestaurantMenuOptionControllerTest extends RestaurantApiTestCase
{
    public function testVariantCrudHappyPath(): void
    {
        $item = $this->menuItem();
        $base = '/api/restaurant/menu-items/'.$item->getId().'/variants';

        $this->client->jsonRequest('POST', $base, ['name' => 'Large', 'price_delta' => '35.00'], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->payload()['variant']['id'];
        /** @var MenuItemVariant $variant */
        $variant = $this->fresh(MenuItemVariant::class, $id);
        self::assertSame('Large', $variant->getName());
        self::assertSame('35.00', $variant->getPriceDelta());

        $this->client->jsonRequest('PATCH', $base.'/'.$id, ['name' => 'Extra Large', 'price_delta' => '50.00'], server: $this->auth());
        self::assertResponseIsSuccessful();
        /** @var MenuItemVariant $variant */
        $variant = $this->fresh(MenuItemVariant::class, $id);
        self::assertSame('Extra Large', $variant->getName());
        self::assertSame('50.00', $variant->getPriceDelta());

        $this->client->request('DELETE', $base.'/'.$id, server: $this->auth());
        self::assertResponseIsSuccessful();
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(MenuItemVariant::class, $id));
    }

    public function testAddonCrudHappyPathAndNegativePriceIsRejected(): void
    {
        $item = $this->menuItem();
        $base = '/api/restaurant/menu-items/'.$item->getId().'/addons';

        $this->client->jsonRequest('POST', $base, ['name' => 'Extra Cheese', 'price' => '20.00', 'is_available' => true], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->payload()['addon']['id'];
        /** @var MenuItemAddon $addon */
        $addon = $this->fresh(MenuItemAddon::class, $id);
        self::assertSame('Extra Cheese', $addon->getName());
        self::assertTrue($addon->isAvailable());

        $this->client->jsonRequest('PATCH', $base.'/'.$id, ['name' => 'Cheese', 'price' => '25.00', 'is_available' => false], server: $this->auth());
        self::assertResponseIsSuccessful();
        /** @var MenuItemAddon $addon */
        $addon = $this->fresh(MenuItemAddon::class, $id);
        self::assertSame('Cheese', $addon->getName());
        self::assertSame('25.00', $addon->getPrice());
        self::assertFalse($addon->isAvailable());

        $this->client->jsonRequest('POST', $base, ['name' => 'Invalid', 'price' => '-1.00'], server: $this->auth());
        self::assertResponseStatusCodeSame(422);

        $this->client->request('DELETE', $base.'/'.$id, server: $this->auth());
        self::assertResponseIsSuccessful();
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(MenuItemAddon::class, $id));
    }

    private function menuItem(): \App\Entity\MenuItem
    {
        $category = $this->createCategory($this->restaurant, 'Options');
        $item = $this->createItem($category, 'Configurable');
        $this->entityManager->flush();

        return $item;
    }
}
