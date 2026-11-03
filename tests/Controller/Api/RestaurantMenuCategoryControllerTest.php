<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\MenuCategory;

final class RestaurantMenuCategoryControllerTest extends RestaurantApiTestCase
{
    public function testCategoryCrudHappyPath(): void
    {
        $this->client->jsonRequest('POST', '/api/restaurant/menu-categories', ['name' => 'Breakfast', 'sort_order' => 2], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->payload()['category']['id'];
        /** @var MenuCategory $category */
        $category = $this->fresh(MenuCategory::class, $id);
        self::assertSame('Breakfast', $category->getName());
        self::assertSame($this->restaurant->getId(), $category->getRestaurant()->getId());

        $this->client->jsonRequest('PATCH', '/api/restaurant/menu-categories/'.$id, ['name' => 'Brunch', 'sort_order' => 4], server: $this->auth());
        self::assertResponseIsSuccessful();
        /** @var MenuCategory $category */
        $category = $this->fresh(MenuCategory::class, $id);
        self::assertSame('Brunch', $category->getName());
        self::assertSame(4, $category->getSortOrder());

        $this->client->request('DELETE', '/api/restaurant/menu-categories/'.$id, server: $this->auth());
        self::assertResponseIsSuccessful();
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(MenuCategory::class, $id));
    }

    public function testDeletingCategoryContainingItemsIsBlocked(): void
    {
        $category = $this->createCategory($this->restaurant, 'Lunch');
        $this->createItem($category, 'Rice Bowl');
        $this->entityManager->flush();

        $this->client->request('DELETE', '/api/restaurant/menu-categories/'.$category->getId(), server: $this->auth());

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('still contains menu items', $this->payload()['message']);
        /** @var MenuCategory $fresh */
        $fresh = $this->fresh(MenuCategory::class, (string) $category->getId());
        self::assertSame($category->getId(), $fresh->getId());
    }
}
