<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\Rider;
use App\Entity\RiderEarning;
use App\Entity\RiderPoolOffer;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\RiderPoolEscalationStage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RiderActiveOrderControllerTest extends RiderApiTestCase
{
    public function testActiveOrderIsScopedToAuthenticatedRiderAndInvalidJumpIsRejected(): void
    {
        $foreign = $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->otherRider);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/rider/active-order', server: $this->auth());
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->payload()['order']);

        $this->client->request('POST', '/api/rider/active-order/start-delivery', server: $this->auth());
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($foreign->getId());
    }

    public function testRiderCanProgressFromAssignmentToCustomerArrivalWithPickupCode(): void
    {
        $order = $this->createOrder(OrderStatus::RIDER_ASSIGNED, $this->rider)->setPickupCode('4321');
        $this->rider->setAvailabilityStatus(RiderAvailabilityStatus::BUSY);
        $this->entityManager->flush();

        $this->client->request('POST', '/api/rider/active-order/start-delivery', server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('picked_up', $this->payload()['message']);

        $this->client->request('POST', '/api/rider/active-order/arrived-at-restaurant', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('at_restaurant', $this->payload()['order']['status']);

        $this->client->jsonRequest('POST', '/api/rider/active-order/confirm-pickup', ['pickup_code' => '9999'], server: $this->auth());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('invalid', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/rider/active-order/confirm-pickup', ['pickup_code' => '4321'], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('picked_up', $this->payload()['order']['status']);
        $this->client->request('POST', '/api/rider/active-order/start-delivery', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('on_the_way', $this->payload()['order']['status']);
        $this->client->request('POST', '/api/rider/active-order/arrived-at-customer', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('arrived', $this->payload()['order']['status']);

        $this->entityManager->clear();
        $saved = $this->entityManager->find(Order::class, $order->getId());
        self::assertInstanceOf(Order::class, $saved);
        self::assertNotNull($saved->getRiderArrivedRestaurantAt());
        self::assertNotNull($saved->getPickedUpAt());
        self::assertCount(4, $saved->getStatusHistory());
    }

    public function testCodDeliveryRequiresCashConfirmationAndCreatesPaymentCashAndEarning(): void
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder(OrderStatus::ARRIVED, $this->rider, PaymentMethod::COD)
            ->setRiderArrivedRestaurantAt($now->modify('-12 minutes'))->setPickedUpAt($now->modify('-2 minutes'));
        $this->rider->setAvailabilityStatus(RiderAvailabilityStatus::BUSY)->setCashOnHand('100.00');
        $offer = (new RiderPoolOffer())->setOrder($order)->setSearchRadiusKm('3.0')->setIncentiveAmount('20.00')
            ->setEscalationStage(RiderPoolEscalationStage::INCENTIVIZED)->setCreatedAt($now)->setUpdatedAt($now);
        $order->setRiderPoolOffer($offer);
        $this->persist($offer);
        $this->entityManager->flush();
        $orderId = (string) $order->getId();
        $riderId = (string) $this->rider->getId();

        $proof = $this->proofUpload();
        $this->client->request('POST', '/api/rider/active-order/confirm-delivery', files: ['proof' => $proof], server: $this->auth());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('cash was collected', $this->payload()['message']);

        $proof = $this->proofUpload();
        $this->client->request('POST', '/api/rider/active-order/confirm-delivery', ['cashCollected' => 'true'], ['proof' => $proof], server: $this->auth());
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame('delivered', $payload['order']['status']);
        self::assertSame('40.00', $payload['earning']['base_pay']);
        self::assertSame('10.00', $payload['earning']['waiting_pay']);
        self::assertSame('20.00', $payload['earning']['incentive_pay']);
        self::assertSame('10.00', $payload['earning']['tip_amount']);
        self::assertNotNull($payload['order']['proof_of_delivery_path']);
        $this->uploadedPaths[] = $payload['order']['proof_of_delivery_path'];

        $this->entityManager->clear();
        $savedOrder = $this->entityManager->find(Order::class, $orderId);
        $savedRider = $this->entityManager->find(Rider::class, $riderId);
        self::assertInstanceOf(Order::class, $savedOrder);
        self::assertInstanceOf(Rider::class, $savedRider);
        self::assertSame(PaymentStatus::PAID, $savedOrder->getPayment()?->getStatus());
        self::assertSame('245.00', $savedRider->getCashOnHand());
        self::assertSame(RiderAvailabilityStatus::AVAILABLE, $savedRider->getAvailabilityStatus());
        self::assertInstanceOf(RiderEarning::class, $savedOrder->getRiderEarning());
        self::assertCount(1, $savedOrder->getPayment()?->getStatusHistory());
    }

    public function testCustomerUnreachableHonorsWaitAndLeavesCodPaymentPending(): void
    {
        $order = $this->createOrder(OrderStatus::ARRIVED, $this->rider, PaymentMethod::COD);
        $arrival = (new OrderStatusHistory())->setOrder($order)->setStatus(OrderStatus::ARRIVED->value)->setChangedBy(OrderActor::RIDER)
            ->setCreatedAt(new \DateTimeImmutable('-6 minutes'));
        $order->addStatusHistory($arrival);
        $this->rider->setAvailabilityStatus(RiderAvailabilityStatus::BUSY)->setCashOnHand('50.00');
        $this->persist($arrival);
        $this->entityManager->flush();

        $this->client->request('POST', '/api/rider/active-order/customer-unreachable', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('failed_delivery', $this->payload()['order']['status']);

        $this->entityManager->clear();
        $saved = $this->entityManager->find(Order::class, $order->getId());
        self::assertInstanceOf(Order::class, $saved);
        self::assertSame(PaymentStatus::PENDING, $saved->getPayment()?->getStatus());
        self::assertSame('50.00', $saved->getRider()?->getCashOnHand());
        self::assertNull($saved->getRiderEarning());
    }

    public function testCustomerUnreachableRejectsBeforeConfiguredWait(): void
    {
        $order = $this->createOrder(OrderStatus::ARRIVED, $this->rider);
        $arrival = (new OrderStatusHistory())->setOrder($order)->setStatus(OrderStatus::ARRIVED->value)->setChangedBy(OrderActor::RIDER)
            ->setCreatedAt(new \DateTimeImmutable('-1 minute'));
        $order->addStatusHistory($arrival);
        $this->persist($arrival);
        $this->entityManager->flush();

        $this->client->request('POST', '/api/rider/active-order/customer-unreachable', server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('timer', $this->payload()['message']);
    }

    public function testCancellationAndIssueReportingRespectStatusAndOwnership(): void
    {
        $order = $this->createOrder(OrderStatus::AT_RESTAURANT, $this->rider);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/rider/active-order/report-issue', ['type' => 'wrong_item', 'description' => 'Restaurant supplied the wrong package.'], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('restaurant', $this->payload()['report']['against']);
        self::assertSame(1, $this->entityManager->getRepository(OrderReport::class)->count(['order' => $order]));

        $this->client->jsonRequest('POST', '/api/rider/active-order/cancel', ['reason' => 'Motorcycle problem.'], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('failed_delivery', $this->payload()['order']['status']);

        $this->client->jsonRequest('POST', '/api/rider/active-order/cancel', ['reason' => 'Again'], server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancellationAfterPickupIsRejected(): void
    {
        $this->createOrder(OrderStatus::PICKED_UP, $this->rider);
        $this->entityManager->flush();
        $this->client->jsonRequest('POST', '/api/rider/active-order/cancel', ['reason' => 'Too late'], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('before pickup', $this->payload()['message']);
    }

    private function proofUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'foodjett-proof-');
        self::assertNotFalse($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        return new UploadedFile($path, 'proof.png', 'image/png', null, true);
    }
}
