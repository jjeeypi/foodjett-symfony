<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\OrderItem;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\RestaurantReview;
use App\Entity\RiderReview;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;

final class CustomerOrderControllerTest extends CustomerApiTestCase
{
    public function testIndexFiltersOrdersAndShowReturnsOwnedDetailAndHistory(): void
    {
        $active = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::PLACED, new \DateTimeImmutable('-1 minute'));
        $past = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::DELIVERED, new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'));
        $foreign = $this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress);
        $history = (new OrderStatusHistory())->setOrder($active)->setStatus(OrderStatus::PLACED->value)->setChangedBy(OrderActor::SYSTEM)
            ->setNote('Placed by checkout')->setCreatedAt(new \DateTimeImmutable('-1 minute'));
        $active->addStatusHistory($history);
        $this->persist($history);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/customer/orders?filter=active', server: $this->auth());
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame(1, $payload['meta']['total']);
        self::assertSame($active->getId(), $payload['data'][0]['id']);
        self::assertNotSame($foreign->getId(), $payload['data'][0]['id']);

        $this->client->request('GET', '/api/customer/orders?filter=past', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame($past->getId(), $this->payload()['data'][0]['id']);

        $this->client->request('GET', '/api/customer/orders/'.$active->getId(), server: $this->auth());
        self::assertResponseIsSuccessful();
        $shown = $this->payload()['order'];
        self::assertSame($active->getOrderNumber(), $shown['order_number']);
        self::assertSame('placed', $shown['status_history'][0]['status']);

        $this->client->request('GET', '/api/customer/orders/'.$foreign->getId(), server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testReorderUsesCurrentPricesAndRejectsUnavailableItems(): void
    {
        $category = $this->createCategory($this->restaurant);
        $menuItem = $this->createItem($category, price: '175.50');
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::DELIVERED, new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'));
        $orderItem = (new OrderItem())->setOrder($order)->setMenuItem($menuItem)->setQuantity(2)->setUnitPrice('99.00')
            ->setSpecialInstructions('Less salt')->setCreatedAt(new \DateTimeImmutable('-2 days'))->setUpdatedAt(new \DateTimeImmutable('-2 days'));
        $order->addItem($orderItem);
        $this->persist($category, $menuItem, $orderItem);
        $this->entityManager->flush();

        $this->client->request('POST', '/api/customer/orders/'.$order->getId().'/reorder', server: $this->auth());
        self::assertResponseIsSuccessful();
        $cart = $this->payload()['cart'];
        self::assertSame($this->restaurant->getId(), $cart['restaurant_id']);
        self::assertSame('175.50', $cart['items'][0]['base_price']);
        self::assertSame('175.50', $cart['items'][0]['current_unit_price']);
        self::assertSame(2, $cart['items'][0]['quantity']);

        $menuItemId = (string) $menuItem->getId();
        $this->entityManager->clear();
        $menuItem = $this->entityManager->find(\App\Entity\MenuItem::class, $menuItemId);
        self::assertInstanceOf(\App\Entity\MenuItem::class, $menuItem);
        $menuItem->setIsAvailable(false);
        $this->entityManager->flush();
        $this->client->request('POST', '/api/customer/orders/'.$order->getId().'/reorder', server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('no longer available', $this->payload()['message']);
    }

    public function testDeliveredOrderCanBeReviewedOnceWithoutAnExpiryWindow(): void
    {
        $rider = $this->createRider();
        $deliveredAt = new \DateTimeImmutable('-1 day');
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::DELIVERED, new \DateTimeImmutable('-2 days'), $deliveredAt)
            ->setRider($rider);
        $oldOrder = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::DELIVERED, new \DateTimeImmutable('-31 days'), new \DateTimeImmutable('-30 days'));
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$order->getId().'/reviews', [
            'restaurant_rating' => 5,
            'rider_rating' => 4,
            'comment' => 'Great meal and delivery.',
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Review submitted.', $this->payload()['message']);
        self::assertSame(1, $this->entityManager->getRepository(RestaurantReview::class)->count(['order' => $order]));
        self::assertSame(1, $this->entityManager->getRepository(RiderReview::class)->count(['order' => $order]));

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$order->getId().'/reviews', ['restaurant_rating' => 3], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already been reviewed', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$oldOrder->getId().'/reviews', ['restaurant_rating' => 5], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Review submitted.', $this->payload()['message']);
    }

    public function testReportsAllowActiveAndRecentlyDeliveredOrdersButRejectOldDeliveries(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address, placedAt: new \DateTimeImmutable('-60 days'));
        $recentlyDelivered = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::DELIVERED, new \DateTimeImmutable('-6 days'), new \DateTimeImmutable('-5 days'));
        $oldDelivery = $this->createOrder($this->customer, $this->restaurant, $this->address, OrderStatus::DELIVERED, new \DateTimeImmutable('-11 days'), new \DateTimeImmutable('-10 days'));
        $foreign = $this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$order->getId().'/reports', [
            'type' => 'missing_item', 'against' => 'restaurant', 'description' => 'One item was missing.',
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('open', $this->payload()['report']['status']);
        self::assertSame(1, $this->entityManager->getRepository(OrderReport::class)->count(['order' => $order]));

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$recentlyDelivered->getId().'/reports', [
            'type' => 'late_delivery', 'against' => 'rider', 'description' => 'The delivery arrived very late.',
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('open', $this->payload()['report']['status']);

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$oldDelivery->getId().'/reports', [
            'type' => 'other', 'against' => 'platform', 'description' => 'This report is too late.',
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('within seven days after delivery', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/customer/orders/'.$foreign->getId().'/reports', [
            'type' => 'other', 'against' => 'platform', 'description' => 'Should not work.',
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }
}
