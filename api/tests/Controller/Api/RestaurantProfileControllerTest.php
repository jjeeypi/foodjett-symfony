<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RestaurantOperatingHour;
use App\Entity\Restaurant;
use App\Enum\RestaurantOperatingStatus;

final class RestaurantProfileControllerTest extends RestaurantApiTestCase
{
    public function testProfileUpdatePersistsAndReturnsEditableFields(): void
    {
        $this->client->jsonRequest('PATCH', '/api/restaurant/profile', [
            'name' => 'Updated Kitchen', 'description' => 'Fresh test food', 'cuisine_type' => 'Filipino',
            'address' => '456 Updated Avenue', 'latitude' => '14.7000000', 'longitude' => '121.1000000',
            'default_prep_time_minutes' => 25, 'min_order_amount' => '199.50', 'payout_method' => 'ewallet',
            'payout_account_details' => ['provider' => 'GCash', 'account' => '09123456789'],
        ], server: $this->auth());

        self::assertResponseIsSuccessful();
        $profile = $this->payload()['restaurant'];
        self::assertSame('Updated Kitchen', $profile['name']);
        self::assertSame('199.50', $profile['min_order_amount']);
        self::assertSame('ewallet', $profile['payout_method']);
        /** @var Restaurant $restaurant */
        $restaurant = $this->fresh(Restaurant::class, (string) $this->restaurant->getId());
        self::assertSame('Updated Kitchen', $restaurant->getName());
        self::assertSame(25, $restaurant->getDefaultPrepTimeMinutes());
        self::assertSame(['provider' => 'GCash', 'account' => '09123456789'], $restaurant->getPayoutAccountDetails());
    }

    public function testOperatingStatusSupportsAllStatesAndRejectsUnknownState(): void
    {
        foreach (['closed', 'temporarily_closed', 'open'] as $status) {
            $this->client->jsonRequest('PATCH', '/api/restaurant/operating-status', ['operating_status' => $status], server: $this->auth());
            self::assertResponseIsSuccessful();
            self::assertSame($status, $this->payload()['operating_status']);
            /** @var Restaurant $restaurant */
            $restaurant = $this->fresh(Restaurant::class, (string) $this->restaurant->getId());
            self::assertSame($status, $restaurant->getOperatingStatus()->value);
        }

        $this->client->jsonRequest('PATCH', '/api/restaurant/operating-status', ['operating_status' => 'busy'], server: $this->auth());
        self::assertResponseStatusCodeSame(422);
        /** @var Restaurant $restaurant */
        $restaurant = $this->fresh(Restaurant::class, (string) $this->restaurant->getId());
        self::assertSame(RestaurantOperatingStatus::OPEN, $restaurant->getOperatingStatus());
    }

    public function testOperatingHoursPersistUpsertAndRejectDuplicateDayPayload(): void
    {
        $this->client->jsonRequest('PUT', '/api/restaurant/operating-hours', ['hours' => [
            ['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '20:00'],
            ['day_of_week' => 2, 'opens_at' => '09:00', 'closes_at' => '21:00'],
        ]], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['hours']);

        $this->client->jsonRequest('PUT', '/api/restaurant/operating-hours', ['hours' => [
            ['day_of_week' => 1, 'opens_at' => '10:00', 'closes_at' => '22:00'],
        ]], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('10:00', $this->payload()['hours'][0]['opens_at']);
        $rows = $this->entityManager->getRepository(RestaurantOperatingHour::class)->findBy(['restaurant' => $this->restaurant]);
        self::assertCount(1, $rows);
        self::assertSame('22:00', $rows[0]->getClosesAt()->format('H:i'));

        $this->client->jsonRequest('PUT', '/api/restaurant/operating-hours', ['hours' => [
            ['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '20:00'],
            ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '21:00'],
        ]], server: $this->auth());
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->entityManager->getRepository(RestaurantOperatingHour::class)->findBy(['restaurant' => $this->restaurant]));
    }
}
