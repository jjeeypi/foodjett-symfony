<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RiderCashRemittance;
use App\Entity\RiderEarning;
use App\Entity\RiderPayout;
use App\Enum\OrderStatus;
use App\Enum\PayoutStatus;
use App\Enum\RemittanceStatus;

final class RiderFinanceControllerTest extends RiderApiTestCase
{
    public function testEarningSummaryBreakdownAndPayoutsAreScopedToRider(): void
    {
        $now = new \DateTimeImmutable();
        $today = $this->earning($this->rider, '50.00', $now);
        $earlier = $this->earning($this->rider, '70.00', $now->modify('-2 days'));
        $foreign = $this->earning($this->otherRider, '999.00', $now);
        $payout = (new RiderPayout())->setRider($this->rider)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
            ->setTotalAmount('120.00')->setStatus(PayoutStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $foreignPayout = (new RiderPayout())->setRider($this->otherRider)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
            ->setTotalAmount('999.00')->setStatus(PayoutStatus::PAID)->setPaidAt($now)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($payout, $foreignPayout);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/rider/earnings/summary', server: $this->auth());
        self::assertResponseIsSuccessful();
        $summary = $this->payload()['summary'];
        self::assertSame('50.00', $summary['today']);
        $expectedWeek = $earlier->getCreatedAt() >= $now->setTime(0, 0)->modify('monday this week') ? '120.00' : '50.00';
        self::assertSame($expectedWeek, $summary['week']);
        $expectedMonth = $earlier->getCreatedAt() >= $now->setTime(0, 0)->modify('first day of this month') ? '120.00' : '50.00';
        self::assertSame($expectedMonth, $summary['month']);
        self::assertNotSame('999.00', $summary['today']);

        $this->client->request('GET', '/api/rider/earnings', server: $this->auth());
        self::assertResponseIsSuccessful();
        $earnings = $this->payload();
        self::assertSame(2, $earnings['meta']['total']);
        self::assertSame($today->getOrder()->getOrderNumber(), $earnings['data'][0]['order']['order_number']);
        self::assertSame('40.00', $earnings['data'][0]['base_pay']);

        $this->client->request('GET', '/api/rider/payouts', server: $this->auth());
        self::assertResponseIsSuccessful();
        $payouts = $this->payload();
        self::assertSame(1, $payouts['meta']['total']);
        self::assertSame($payout->getId(), $payouts['data'][0]['id']);
        self::assertNotSame($foreignPayout->getId(), $payouts['data'][0]['id']);
        self::assertNotNull($foreign->getId());
    }

    public function testCashStatusAndRemittanceRespectPendingReservation(): void
    {
        $now = new \DateTimeImmutable();
        $this->rider->setCashOnHand('500.00')->setCashRemitLimit('1000.00');
        $pending = (new RiderCashRemittance())->setRider($this->rider)->setAmount('100.00')->setReferenceNote('First deposit')
            ->setStatus(RemittanceStatus::PENDING)->setCreatedAt($now->modify('-1 day'))->setUpdatedAt($now->modify('-1 day'));
        $foreign = (new RiderCashRemittance())->setRider($this->otherRider)->setAmount('50.00')->setStatus(RemittanceStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($pending, $foreign);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/rider/cash-status', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame('400.00', $this->payload()['cash_status']['available_to_remit']);

        $this->client->jsonRequest('POST', '/api/rider/remittances', ['amount' => 450, 'reference_note' => 'Too much'], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('exceeds cash available', $this->payload()['message']);

        $this->client->jsonRequest('POST', '/api/rider/remittances', ['amount' => 300, 'reference_note' => 'Second deposit'], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->payload()['remittance']['status']);

        $this->client->request('GET', '/api/rider/remittances', server: $this->auth());
        self::assertResponseIsSuccessful();
        $list = $this->payload();
        self::assertSame(2, $list['meta']['total']);
        self::assertNotContains($foreign->getId(), array_column($list['data'], 'id'));
    }

    private function earning(\App\Entity\Rider $rider, string $total, \DateTimeImmutable $at): RiderEarning
    {
        $order = $this->createOrder(OrderStatus::DELIVERED, $rider, at: $at)->setDeliveredAt($at);
        $earning = (new RiderEarning())->setRider($rider)->setOrder($order)->setBasePay('40.00')->setDistancePay('0.00')
            ->setWaitingPay('0.00')->setIncentivePay('0.00')->setTipAmount('10.00')->setTotalEarned($total)->setCreatedAt($at)->setUpdatedAt($at);
        $order->setRiderEarning($earning);
        $this->persist($earning);
        return $earning;
    }
}
