<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\MenuItem;
use App\Entity\OrderItem;
use App\Enum\OrderStatus;

final class RestaurantMenuItemControllerTest extends RestaurantApiTestCase
{
    public function testItemCrudAndAvailabilityToggleHappyPath(): void
    {
        $category = $this->createCategory($this->restaurant, 'Mains');
        $this->entityManager->flush();
        $this->client->jsonRequest('POST', '/api/restaurant/menu-items', [
            'menu_category_id' => $category->getId(), 'name' => 'Chicken Adobo', 'description' => 'Classic adobo',
            'base_price' => '149.50', 'is_available' => true, 'is_featured' => false,
            'available_from' => '09:00', 'available_until' => '21:30',
        ], server: $this->auth());

        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->payload()['item']['id'];
        /** @var MenuItem $item */
        $item = $this->fresh(MenuItem::class, $id);
        self::assertSame('Chicken Adobo', $item->getName());
        self::assertSame('149.50', $item->getBasePrice());
        self::assertSame($category->getId(), $item->getMenuCategory()->getId());

        $this->client->jsonRequest('PATCH', '/api/restaurant/menu-items/'.$id, [
            'name' => 'Special Chicken Adobo', 'base_price' => '159.00', 'is_featured' => true,
        ], server: $this->auth());
        self::assertResponseIsSuccessful();
        /** @var MenuItem $item */
        $item = $this->fresh(MenuItem::class, $id);
        self::assertSame('Special Chicken Adobo', $item->getName());
        self::assertSame('159.00', $item->getBasePrice());
        self::assertTrue($item->isFeatured());

        $this->client->jsonRequest('PATCH', '/api/restaurant/menu-items/'.$id.'/toggle-availability', [], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['is_available']);
        /** @var MenuItem $item */
        $item = $this->fresh(MenuItem::class, $id);
        self::assertFalse($item->isAvailable());

        $this->client->request('DELETE', '/api/restaurant/menu-items/'.$id, server: $this->auth());
        self::assertResponseIsSuccessful();
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(MenuItem::class, $id));
    }

    public function testDeletingItemReferencedByOrderReturnsConflictAndMarksItUnavailable(): void
    {
        $category = $this->createCategory($this->restaurant, 'Historical');
        $item = $this->createItem($category, 'Historic Meal');
        $order = $this->createOrder($this->restaurant, OrderStatus::DELIVERED, deliveredAt: new \DateTimeImmutable());
        $now = new \DateTimeImmutable();
        $orderItem = (new OrderItem())->setOrder($order)->setMenuItem($item)->setQuantity(1)->setUnitPrice('99.00')
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($orderItem);
        $this->entityManager->flush();

        $this->client->request('DELETE', '/api/restaurant/menu-items/'.$item->getId(), server: $this->auth());

        self::assertResponseStatusCodeSame(409);
        self::assertFalse($this->payload()['is_available']);
        self::assertStringContainsString('order history', $this->payload()['message']);
        /** @var MenuItem $fresh */
        $fresh = $this->fresh(MenuItem::class, (string) $item->getId());
        self::assertFalse($fresh->isAvailable());
    }

    public function testItemCannotUseAnotherRestaurantsCategory(): void
    {
        $foreignCategory = $this->createCategory($this->otherRestaurant, 'Foreign');
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/restaurant/menu-items', [
            'menu_category_id' => $foreignCategory->getId(), 'name' => 'Invalid Item', 'base_price' => '100.00',
        ], server: $this->auth());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('menu_category_id', $this->payload()['errors']);
    }
}
