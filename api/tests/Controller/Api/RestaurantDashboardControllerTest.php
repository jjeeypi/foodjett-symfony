<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\OrderStatus;

final class RestaurantDashboardControllerTest extends RestaurantApiTestCase
{
    public function testMetricsAndRecentOrdersAreAccurateOrderedAndScoped(): void
    {
        $now = new \DateTimeImmutable();
        $older = $this->createOrder($this->restaurant, OrderStatus::PLACED, '80.00', '115.00', $now->modify('-30 minutes'));
        $newer = $this->createOrder($this->restaurant, OrderStatus::DELIVERED, '120.00', '155.00', $now->modify('-10 minutes'), $now->modify('-5 minutes'));
        $this->createOrder($this->restaurant, OrderStatus::DELIVERED, '50.00', '85.00', $now->modify('-2 days'), $now->modify('-2 days'));
        $foreign = $this->createOrder($this->otherRestaurant, OrderStatus::DELIVERED, '999.00', '1034.00', $now, $now);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurant/dashboard', server: $this->auth());

        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame(2, $payload['today_order_count']);
        self::assertSame('155.00', $payload['today_revenue']);
        self::assertSame(1, $payload['pending_orders_count']);
        self::assertSame([(string) $newer->getId(), (string) $older->getId()], array_slice(array_column($payload['recent_orders'], 'id'), 0, 2));
        self::assertNotContains((string) $foreign->getId(), array_column($payload['recent_orders'], 'id'));
    }
}
