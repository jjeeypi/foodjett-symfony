<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\User;

/** The report pages: customers track what they reported, restaurants and riders see reports about them. */
class ReportPagesTest extends RiderWebTestCase
{
    public function testEachSideSeesOnlyItsOwnReportsWithTheAdminDecision(): void
    {
        $order = $this->createOrder(Order::STATUS_DELIVERED, $this->rider);
        $otherOrder = $this->createOrder(Order::STATUS_DELIVERED, $this->otherRider);
        $customerUser = $this->customer->getUser();
        $restaurantUser = $this->restaurant->getUser();

        $missing = $this->report($order, $customerUser, OrderReport::AGAINST_RESTAURANT, OrderReport::TYPE_MISSING_ITEM, 'The fries were missing.')
            ->setStatus(OrderReport::STATUS_RESOLVED)->setResolution('Refunded the fries.')->setResolvedAt(new \DateTimeImmutable());
        $late = $this->report($order, $customerUser, OrderReport::AGAINST_RIDER, OrderReport::TYPE_LATE_DELIVERY, 'Forty minutes late.');
        $wrong = $this->report($order, $this->riderUser, OrderReport::AGAINST_RESTAURANT, OrderReport::TYPE_WRONG_ITEM, 'Packed the wrong bag.');
        $this->report($otherOrder, $customerUser, OrderReport::AGAINST_RIDER, OrderReport::TYPE_RUDE_BEHAVIOR, 'About a different rider.');
        $this->entityManager->flush();

        $this->client->loginUser($customerUser, 'main');
        $crawler = $this->client->request('GET', '/reports');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('#reports article'), 'Only what this customer reported.');
        self::assertSelectorTextContains('#report-'.$missing->getId(), 'Refunded the fries.');
        self::assertSelectorTextContains('#report-'.$late->getId(), 'An admin will look into this');
        $this->client->request('GET', '/rider/reports');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($restaurantUser, 'main');
        $crawler = $this->client->request('GET', '/restaurant/reports');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#reports article'), 'Only reports about the restaurant.');
        self::assertSelectorTextContains('#report-'.$wrong->getId(), 'reported by a rider');
        self::assertSelectorTextContains('#summary', 'Waiting for a decision');
        self::assertSame(['2', '1', '1'], $crawler->filter('#summary dd')->slice(0, 3)->each(static fn ($dd): string => trim($dd->text())));
        self::assertSelectorTextNotContains('#reports', 'Forty minutes late.');

        $this->client->loginUser($this->riderUser, 'main');
        $crawler = $this->client->request('GET', '/rider/reports');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#reports article'), 'Only reports about this rider.');
        self::assertSelectorTextContains('#report-'.$late->getId(), 'Late delivery');
        self::assertSelectorTextNotContains('#reports', 'About a different rider.');
    }

    public function testEmptyPagesExplainWhereReportsComeFrom(): void
    {
        $this->client->loginUser($this->riderUser, 'main');
        $this->client->request('GET', '/rider/reports');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty', 'No reports about your deliveries.');

        $this->client->loginUser($this->customer->getUser(), 'main');
        $this->client->request('GET', '/reports');
        self::assertSelectorTextContains('.empty', 'Report a problem');
    }

    private function report(Order $order, User $by, string $against, string $type, string $description): OrderReport
    {
        $now = new \DateTimeImmutable();
        $report = (new OrderReport())->setOrder($order)->setReportedBy($by)->setAgainst($against)->setType($type)
            ->setDescription($description)->setStatus(OrderReport::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($report);

        return $report;
    }
}
