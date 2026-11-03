<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\OrderItem;

final class RestaurantOrderQueueControllerTest extends RestaurantApiTestCase
{
    public function testQueueSummaryIncludesItemsInstructionsAndOnlyOwnedOrders(): void
    {
        $category = $this->createCategory($this->restaurant, 'Mains');
        $menuItem = $this->createItem($category, 'Adobo Bowl');
        $order = $this->createOrder($this->restaurant);
        $order->setCustomerNotes('Please include utensils.');
        $orderItem = (new OrderItem())
            ->setOrder($order)
            ->setMenuItem($menuItem)
            ->setQuantity(2)
            ->setUnitPrice('99.00')
            ->setSpecialInstructions('No onions')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->createOrder($this->otherRestaurant);
        $this->persist($orderItem);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurant/orders?perPage=100', server: $this->auth());

        self::assertResponseIsSuccessful();
        $orders = $this->payload()['data'] ?? [];
        self::assertCount(1, $orders);
        self::assertSame((string) $order->getId(), $orders[0]['id'] ?? null);
        self::assertSame('Please include utensils.', $orders[0]['customer_notes'] ?? null);
        self::assertSame($menuItem->getName(), $orders[0]['items'][0]['name'] ?? null);
        self::assertSame(2, $orders[0]['items'][0]['quantity'] ?? null);
        self::assertSame('No onions', $orders[0]['items'][0]['special_instructions'] ?? null);
    }
}
