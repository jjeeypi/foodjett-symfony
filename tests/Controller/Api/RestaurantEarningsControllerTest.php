<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RestaurantPayout;
use App\Enum\OrderStatus;
use App\Enum\PayoutStatus;

final class RestaurantEarningsControllerTest extends RestaurantApiTestCase
{
    public function testSummaryMatchesDeliveredOrdersForTodayWeekAndMonth(): void
    {
        $now = new \DateTimeImmutable();
        $samples = [
            [$now->modify('-5 minutes'), 100.0, 15.0],
            [$now->modify('-2 days'), 200.0, 30.0],
            [$now->modify('-10 days'), 300.0, 45.0],
        ];
        foreach ($samples as [$deliveredAt, $subtotal, $commission]) {
            $this->createOrder($this->restaurant, OrderStatus::DELIVERED, number_format($subtotal, 2, '.', ''), number_format($subtotal + 35, 2, '.', ''), $deliveredAt, $deliveredAt, number_format($commission, 2, '.', ''));
        }
        $this->createOrder($this->restaurant, OrderStatus::PLACED, '500.00', '535.00', $now);
        $this->createOrder($this->otherRestaurant, OrderStatus::DELIVERED, '999.00', '1034.00', $now, $now, '149.85');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurant/earnings/summary', server: $this->auth());

        self::assertResponseIsSuccessful();
        $summary = $this->payload()['summary'];
        $starts = [
            'today' => $now->setTime(0, 0),
            'week' => $now->setTime(0, 0)->modify('monday this week'),
            'month' => $now->setTime(0, 0)->modify('first day of this month'),
        ];
        foreach ($starts as $key => $start) {
            $included = array_filter($samples, static fn (array $sample): bool => $sample[0] >= $start && $sample[0] <= $now);
            $gross = array_sum(array_column($included, 1));
            $commission = array_sum(array_column($included, 2));
            self::assertSame(count($included), $summary[$key]['order_count']);
            self::assertSame(number_format($gross, 2, '.', ''), $summary[$key]['gross_revenue']);
            self::assertSame(number_format($commission, 2, '.', ''), $summary[$key]['commission']);
            self::assertSame(number_format($gross - $commission, 2, '.', ''), $summary[$key]['net_revenue']);
        }
    }

    public function testPayoutListIsScopedAndReadOnly(): void
    {
        $now = new \DateTimeImmutable();
        $own = $this->payout($this->restaurant, '1000.00', $now);
        $foreign = $this->payout($this->otherRestaurant, '9000.00', $now);
        $this->persist($own, $foreign);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurant/payouts', server: $this->auth());
        self::assertResponseIsSuccessful();
        $ids = array_column($this->payload()['data'], 'id');
        self::assertSame([(string) $own->getId()], $ids);
        self::assertNotContains((string) $foreign->getId(), $ids);

        $this->client->jsonRequest('POST', '/api/restaurant/payouts', ['status' => 'paid'], server: $this->auth());
        self::assertResponseStatusCodeSame(405);
        /** @var RestaurantPayout $fresh */
        $fresh = $this->fresh(RestaurantPayout::class, (string) $own->getId());
        self::assertSame(PayoutStatus::PENDING, $fresh->getStatus());
    }

    private function payout(\App\Entity\Restaurant $restaurant, string $gross, \DateTimeImmutable $now): RestaurantPayout
    {
        $commission = (float) $gross * 0.15;

        return (new RestaurantPayout())->setRestaurant($restaurant)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
            ->setGrossSales($gross)->setCommissionDeducted(number_format($commission, 2, '.', ''))
            ->setNetAmount(number_format((float) $gross - $commission, 2, '.', ''))->setStatus(PayoutStatus::PENDING)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }
}
