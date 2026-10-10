<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\RiderEarning;
use App\Entity\RiderPayout;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PayoutStatus;
use App\Enum\RemittanceStatus;
use App\Enum\UserStatus;

final class RiderAccountControllerTest extends RiderApiTestCase
{
    public function testProfileUpdatePersistsVehicleAndEncryptedPayoutDetails(): void
    {
        $this->client->jsonRequest('PATCH', '/api/rider/profile', [
            'name' => 'Updated Rider', 'phone' => '+639170001111', 'vehicle_type' => 'car', 'plate_number' => 'CAR-777',
            'payout_method' => 'ewallet', 'payout_account_details' => ['provider' => 'GCash', 'account' => '09171234567'],
        ], server: $this->auth());

        self::assertResponseIsSuccessful();
        $profile = $this->payload()['profile'];
        self::assertSame('Updated Rider', $profile['name']);
        self::assertSame('car', $profile['vehicle_type']);
        self::assertSame(['provider' => 'GCash', 'account' => '09171234567'], $profile['payout_account_details']);

        $raw = (string) $this->entityManager->getConnection()->fetchOne('SELECT payout_account_details FROM riders WHERE id = ?', [$this->rider->getId()]);
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString('09171234567', $raw);
        self::assertStringNotContainsString('GCash', $raw);
    }

    public function testPlateIsRequiredExceptForBicycles(): void
    {
        $this->client->jsonRequest('PATCH', '/api/rider/profile', ['vehicle_type' => 'motorcycle', 'plate_number' => ''], server: $this->auth());
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('plate_number', $this->payload()['errors']);

        $this->client->jsonRequest('PATCH', '/api/rider/profile', ['vehicle_type' => 'bicycle', 'plate_number' => ''], server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('bicycle', $this->payload()['profile']['vehicle_type']);
        self::assertNull($this->payload()['profile']['plate_number']);
    }

    public function testAccountDeletionAnonymizesRiderButPreservesOperationalHistory(): void
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder(OrderStatus::DELIVERED, $this->rider, at: $now)->setDeliveredAt($now);
        $earning = (new RiderEarning())->setRider($this->rider)->setOrder($order)->setBasePay('40.00')->setDistancePay('10.00')
            ->setWaitingPay('0.00')->setIncentivePay('0.00')->setTipAmount('0.00')->setTotalEarned('50.00')->setCreatedAt($now)->setUpdatedAt($now);
        $order->setRiderEarning($earning);
        $payout = (new RiderPayout())->setRider($this->rider)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
            ->setTotalAmount('50.00')->setStatus(PayoutStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $remittance = (new RiderCashRemittance())->setRider($this->rider)->setAmount('25.00')->setStatus(RemittanceStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($earning, $payout, $remittance);
        $this->entityManager->flush();
        $ids = [(string) $this->riderUser->getId(), (string) $this->rider->getId(), (string) $order->getId(), (string) $earning->getId(), (string) $payout->getId(), (string) $remittance->getId()];

        $this->client->request('DELETE', '/api/rider/account', server: $this->auth());
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $ids[0]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Deleted Rider', $user->getName());
        self::assertNull($user->getEmail());
        self::assertNull($user->getPhone());
        self::assertSame(UserStatus::BANNED, $user->getStatus());
        self::assertInstanceOf(Rider::class, $this->entityManager->find(Rider::class, $ids[1]));
        self::assertNotNull($this->entityManager->find(Order::class, $ids[2]));
        self::assertNotNull($this->entityManager->find(RiderEarning::class, $ids[3]));
        self::assertNotNull($this->entityManager->find(RiderPayout::class, $ids[4]));
        self::assertNotNull($this->entityManager->find(RiderCashRemittance::class, $ids[5]));
    }
}
