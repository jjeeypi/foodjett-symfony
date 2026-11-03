<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\OrderStatus;

final class RestaurantDashboardControllerTest extends RestaurantApiTestCase
{
    public function testMetricsAndRecentOrdersAreAccurateOrderedAndScoped(): void
    {
        // Fixed times within today avoid a false failure during the first 30 minutes after midnight.
        $today = new \DateTimeImmutable('today');
        $olderAt = $today->setTime(10, 0);
        $newerAt = $today->setTime(11, 0);
        $foreignAt = $today->setTime(12, 0);
        $older = $this->createOrder($this->restaurant, OrderStatus::PLACED, '80.00', '115.00', $olderAt);
        $newer = $this->createOrder($this->restaurant, OrderStatus::DELIVERED, '120.00', '155.00', $newerAt, $newerAt);
        $this->createOrder($this->restaurant, OrderStatus::DELIVERED, '50.00', '85.00', $today->modify('-2 days'), $today->modify('-2 days'));
        $foreign = $this->createOrder($this->otherRestaurant, OrderStatus::DELIVERED, '999.00', '1034.00', $foreignAt, $foreignAt);
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
