<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Rider;
use App\Enum\OrderStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Tests\Double\RecordingMercureHub;

final class RiderAvailabilityLocationControllerTest extends RiderApiTestCase
{
    public function testAvailabilityGoesOnlineWithLocationAndCanReturnOffline(): void
    {
        $this->client->jsonRequest('PATCH', '/api/rider/availability', ['latitude' => 14.6001, 'longitude' => 120.9852], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('available', $this->payload()['availability_status']);

        $riderId = (string) $this->rider->getId();
        $this->entityManager->clear();
        $rider = $this->entityManager->find(Rider::class, $riderId);
        self::assertInstanceOf(Rider::class, $rider);
        self::assertSame('14.6001000', $rider->getCurrentLatitude());
        self::assertNotNull($rider->getLastLocationAt());

        $this->client->jsonRequest('PATCH', '/api/rider/availability', [], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('offline', $this->payload()['availability_status']);
    }

    public function testBusyLockPreventsGoingOfflineDuringAnActiveDelivery(): void
    {
        $this->rider->setAvailabilityStatus(RiderAvailabilityStatus::AVAILABLE);
        $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->rider);
        $this->entityManager->flush();

        $this->client->jsonRequest('PATCH', '/api/rider/availability', [], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Finish your current delivery first.', $this->payload()['message']);
    }

    public function testLocationWritesOncePerFiveSecondsAndRequiresOwnActiveOrder(): void
    {
        $hub = self::getContainer()->get(RecordingMercureHub::class);
        $hub->reset();
        $this->rider->setLastLocationAt(new \DateTimeImmutable('-10 seconds'));
        $order = $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->rider);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/rider/location', ['latitude' => 14.601, 'longitude' => 120.986], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['updated']);
        self::assertSame($order->getId(), $this->payload()['order_id']);
        self::assertCount(1, $hub->updates());
        $update = $hub->updates()[0];
        self::assertSame(['orders/'.$order->getId().'/status'], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertSame('rider.location_updated', $update->getType());
        $locationPayload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('14.6010000', $locationPayload['latitude'] ?? null);
        self::assertSame('120.9860000', $locationPayload['longitude'] ?? null);

        $this->client->jsonRequest('POST', '/api/rider/location', ['latitude' => 14.700, 'longitude' => 121.100], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['updated']);
        self::assertTrue($this->payload()['throttled']);
        self::assertCount(1, $hub->updates());
    }

    public function testAnotherRidersOrderDoesNotAuthorizeLocationUpdates(): void
    {
        $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->otherRider);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/rider/location', ['latitude' => 14.601, 'longitude' => 120.986], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('active delivery', $this->payload()['message']);
    }
}
