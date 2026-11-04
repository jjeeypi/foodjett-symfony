<?php

namespace App\Tests\Controller;

use App\Entity\OrderItem;

/** The kitchen queue lists only the restaurant's own orders, with items and instructions. */
class RestaurantOrderQueuePageTest extends RestaurantWebTestCase
{
    public function testQueueShowsItemsInstructionsAndOnlyOwnedOrders(): void
    {
        $menuItem = $this->createItem($this->createCategory($this->restaurant, 'Mains'), 'Adobo Bowl');
        $order = $this->createOrder($this->restaurant);
        $order->setCustomerNotes('Please include utensils.');
        $orderItem = (new OrderItem())->setOrder($order)->setMenuItem($menuItem)->setQuantity(2)->setUnitPrice('99.00')
            ->setSpecialInstructions('No onions')->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $order->addItem($orderItem);
        $foreign = $this->createOrder($this->otherRestaurant);
        $this->persist($orderItem);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->client->loginUser($this->restaurantUser, 'main');

        $crawler = $this->client->request('GET', '/restaurant/orders');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        $row = $crawler->filter('tbody tr')->text();
        self::assertStringContainsString($order->getOrderNumber(), $row);
        self::assertStringContainsString('2 × '.$menuItem->getName(), $row);
        self::assertStringContainsString('No onions', $row);
        self::assertStringContainsString('Please include utensils.', $row);

        foreach (['GET' => '/restaurant/orders/'.$foreign->getId(), 'POST' => '/restaurant/orders/'.$foreign->getId().'/accept'] as $method => $url) {
            $this->client->request($method, $url, ['accept' => ['minutes' => '15']]);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }
}
