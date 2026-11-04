<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\Rider;
use App\Entity\RiderEarning;
use App\Entity\RiderPoolOffer;
use App\Repository\DeliveryZoneRepository;
use App\Tests\Double\RecordingMercureHub;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/** The rider dashboard (availability, location) and the delivery steps. */
class RiderDeliveryPagesTest extends RiderWebTestCase
{
    private string $photo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->riderUser, 'main');
        $this->photo = sys_get_temp_dir().'/foodjett-proof.png';
        file_put_contents($this->photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([self::getContainer()->getParameter('app.private_upload_dir'), $this->photo]);
        parent::tearDown();
    }

    public function testRiderGoesOnlineAndOfflineButNotDuringADelivery(): void
    {
        $this->submit('/rider', 'Go online');
        self::assertSame(Rider::AVAILABILITY_AVAILABLE, $this->reload(Rider::class, $this->rider->getId())->getAvailabilityStatus());
        $this->submit('/rider', 'Go offline');
        self::assertSame(Rider::AVAILABILITY_OFFLINE, $this->reload(Rider::class, $this->rider->getId())->getAvailabilityStatus());

        $this->reattach();
        $this->rider->setAvailabilityStatus(Rider::AVAILABILITY_BUSY);
        $this->createOrder(Order::STATUS_RIDER_ASSIGNED, $this->rider);
        $this->entityManager->flush();
        $crawler = $this->page('/rider');
        self::assertCount(0, $crawler->filter('#availability button'), 'No switch during a delivery.');
        self::assertSelectorExists('#active-order');
    }

    public function testAPendingRiderSeesTheApplicationStatusButNotThePool(): void
    {
        $this->rider->setApprovalStatus(Rider::APPROVAL_PENDING);
        $this->entityManager->flush();

        $this->page('/rider');
        self::assertSelectorTextContains('#approval', 'Application status: pending');
        $this->client->request('GET', '/rider/pool');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLocationIsSavedEveryFiveSecondsAndSharedOnlyDuringADelivery(): void
    {
        $hub = self::getContainer()->get(RecordingMercureHub::class);
        $this->reload(Rider::class, $this->rider->getId())->setLastLocationAt(new \DateTimeImmutable('-10 seconds'));
        $this->entityManager->flush();
        $hub->reset();
        $this->submit('/rider', 'Share location', ['location_form[latitude]' => '9.315412', 'location_form[longitude]' => '123.302528']);
        self::assertSame('9.3154120', $this->reload(Rider::class, $this->rider->getId())->getCurrentLatitude());
        self::assertCount(0, $hub->updates(), 'Nothing is published without a delivery.');

        $this->reattach();
        $this->rider->setLastLocationAt(new \DateTimeImmutable('-10 seconds'));
        $order = $this->createOrder(Order::STATUS_RIDER_ASSIGNED, $this->rider);
        $this->entityManager->flush();
        $hub->reset();
        $this->submit('/rider', 'Share location', ['location_form[latitude]' => '14.601', 'location_form[longitude]' => '120.986']);
        self::assertCount(1, $hub->updates());
        self::assertSame(['orders/'.$order->getId().'/status'], $hub->updates()[0]->getTopics());
        self::assertSame('<turbo-stream action="refresh"></turbo-stream>', $hub->updates()[0]->getData());
        self::assertSame('14.6010000', $this->reload(Rider::class, $this->rider->getId())->getCurrentLatitude());

        $this->submit('/rider', 'Share location', ['location_form[latitude]' => '14.700', 'location_form[longitude]' => '121.100']);
        self::assertCount(1, $hub->updates(), 'A second update within five seconds is ignored.');
        self::assertSame('14.6010000', $this->reload(Rider::class, $this->rider->getId())->getCurrentLatitude());
    }

    public function testDeliveryPageShowsOnlyTheRidersOwnOrder(): void
    {
        $this->createOrder(Order::STATUS_RIDER_ASSIGNED, $this->otherRider);
        $this->entityManager->flush();

        $this->client->request('GET', '/rider/delivery');
        self::assertResponseRedirects('/rider');
        $this->client->request('POST', '/rider/delivery/step/start', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRiderStepsFromAssignmentToArrivalWithThePickupCode(): void
    {
        $order = $this->createOrder(Order::STATUS_RIDER_ASSIGNED, $this->rider)->setPickupCode('4321');
        $this->rider->setAvailabilityStatus(Rider::AVAILABILITY_BUSY);
        $this->entityManager->flush();

        $this->submit('/rider/delivery', 'I am at the restaurant');
        $this->submit('/rider/delivery', 'Confirm pickup', ['pickup_code_form[pickupCode]' => '9999']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'The pickup code is not right.');
        $this->submit('/rider/delivery', 'Confirm pickup', ['pickup_code_form[pickupCode]' => '4321']);
        $this->submit('/rider/delivery', 'Start the delivery');
        $this->submit('/rider/delivery', 'I have arrived');

        $saved = $this->reload(Order::class, $order->getId());
        self::assertSame(Order::STATUS_ARRIVED, $saved->getStatus());
        self::assertNotNull($saved->getRiderArrivedRestaurantAt());
        self::assertNotNull($saved->getPickedUpAt());
        self::assertCount(4, $saved->getStatusHistory());
    }

    public function testCodDeliveryNeedsCashConfirmationAndRecordsPaymentCashAndEarning(): void
    {
        $now = new \DateTimeImmutable();
        $order = $this->createOrder(Order::STATUS_ARRIVED, $this->rider, Payment::METHOD_COD)
            ->setRiderArrivedRestaurantAt($now->modify('-12 minutes'))->setPickedUpAt($now->modify('-2 minutes'));
        $this->rider->setAvailabilityStatus(Rider::AVAILABILITY_BUSY)->setCashOnHand('100.00');
        $offer = (new RiderPoolOffer())->setOrder($order)->setSearchRadiusKm('3.0')->setIncentiveAmount('20.00')
            ->setAcceptedPickupDistanceKm('2.5000')->setEscalationStage(RiderPoolOffer::STAGE_INCENTIVIZED)->setCreatedAt($now)->setUpdatedAt($now);
        $order->setRiderPoolOffer($offer);
        $this->persist($offer);
        $this->entityManager->flush();

        $form = $this->page('/rider/delivery')->selectButton('Complete delivery')->form();
        $form['delivery_proof_form[proof]']->upload($this->photo);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Confirm that you collected the cash');

        $form = $this->page('/rider/delivery')->selectButton('Complete delivery')->form();
        $form['delivery_proof_form[proof]']->upload($this->photo);
        $form['delivery_proof_form[cashCollected]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/rider');

        $saved = $this->reload(Order::class, $order->getId());
        self::assertSame(Order::STATUS_DELIVERED, $saved->getStatus());
        self::assertSame(Payment::STATUS_PAID, $saved->getPayment()?->getStatus());
        self::assertFileExists(self::getContainer()->getParameter('app.private_upload_dir').'/'.$saved->getProofOfDeliveryPath());
        $earning = $saved->getRiderEarning();
        self::assertInstanceOf(RiderEarning::class, $earning);
        self::assertSame('40.00', $earning->getBasePay());
        $deliveryDistance = DeliveryZoneRepository::distanceKm(14.5995000, 120.9842000, 14.6091000, 121.0223000);
        self::assertSame(number_format(round((2.5 + $deliveryDistance) * 10, 2), 2, '.', ''), $earning->getDistancePay());
        self::assertSame('10.00', $earning->getWaitingPay());
        self::assertSame('20.00', $earning->getIncentivePay());
        self::assertSame('245.00', $saved->getRider()?->getCashOnHand());
        self::assertSame(Rider::AVAILABILITY_AVAILABLE, $saved->getRider()?->getAvailabilityStatus());
    }

    public function testDeliveryIsRefusedWhenThePickupDistanceIsMissing(): void
    {
        $order = $this->createOrder(Order::STATUS_ARRIVED, $this->rider);
        $offer = (new RiderPoolOffer())->setOrder($order)->setSearchRadiusKm('3.0')->setIncentiveAmount('0.00')
            ->setEscalationStage(RiderPoolOffer::STAGE_INITIAL)->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $order->setRiderPoolOffer($offer);
        $this->persist($offer);
        $this->entityManager->flush();

        $form = $this->page('/rider/delivery')->selectButton('Complete delivery')->form();
        $form['delivery_proof_form[proof]']->upload($this->photo);
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'pickup distance is missing');
        self::assertSame(Order::STATUS_ARRIVED, $this->reload(Order::class, $order->getId())->getStatus());
    }

    public function testCustomerUnreachableWaitsForTheTimerAndLeavesCodPending(): void
    {
        $order = $this->createOrder(Order::STATUS_ARRIVED, $this->rider, Payment::METHOD_COD);
        $arrival = (new OrderStatusHistory())->setOrder($order)->setStatus(Order::STATUS_ARRIVED)->setChangedBy(Order::ACTOR_RIDER)
            ->setCreatedAt(new \DateTimeImmutable('-1 minute'));
        $order->addStatusHistory($arrival);
        $this->rider->setAvailabilityStatus(Rider::AVAILABILITY_BUSY)->setCashOnHand('50.00');
        $this->persist($arrival);
        $this->entityManager->flush();

        $this->submit('/rider/delivery', 'Customer unreachable');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Please wait for the customer until');

        $this->reload(OrderStatusHistory::class, $arrival->getId())->setCreatedAt(new \DateTimeImmutable('-6 minutes'));
        $this->entityManager->flush();
        $this->submit('/rider/delivery', 'Customer unreachable');
        self::assertResponseRedirects('/rider');
        $saved = $this->reload(Order::class, $order->getId());
        self::assertSame(Order::STATUS_FAILED_DELIVERY, $saved->getStatus());
        self::assertSame(Payment::STATUS_PENDING, $saved->getPayment()?->getStatus());
        self::assertSame('50.00', $saved->getRider()?->getCashOnHand());
        self::assertNull($saved->getRiderEarning());
    }

    public function testIssuesAreReportedAndCancellingIsOnlyBeforePickup(): void
    {
        $order = $this->createOrder(Order::STATUS_AT_RESTAURANT, $this->rider);
        $this->entityManager->flush();

        $this->submit('/rider/delivery', 'Send report', ['rider_issue_form[type]' => OrderReport::TYPE_WRONG_ITEM, 'rider_issue_form[description]' => 'Restaurant supplied the wrong package.']);
        $report = $this->entityManager->getRepository(OrderReport::class)->findOneBy(['order' => $order->getId()]);
        self::assertSame(OrderReport::AGAINST_RESTAURANT, $report->getAgainst());

        $this->submit('/rider/delivery', 'Cancel this delivery', ['cancel_delivery[reason]' => 'Motorcycle problem.']);
        self::assertResponseRedirects('/rider');
        self::assertSame(Order::STATUS_FAILED_DELIVERY, $this->reload(Order::class, $order->getId())->getStatus());

        $this->reattach();
        $picked = $this->createOrder(Order::STATUS_PICKED_UP, $this->rider);
        $this->entityManager->flush();
        $crawler = $this->page('/rider/delivery');
        self::assertCount(0, $crawler->filter('button:contains("Cancel this delivery")'));
        self::assertNotNull($picked->getId());
    }

    /** Reloads the shared fixtures after the entity manager was cleared, so new rows can point at them. */
    private function reattach(): void
    {
        $this->entityManager->clear();
        $this->rider = $this->entityManager->find(Rider::class, $this->rider->getId());
        $this->otherRider = $this->entityManager->find(Rider::class, $this->otherRider->getId());
        $this->restaurant = $this->entityManager->find(\App\Entity\Restaurant::class, $this->restaurant->getId());
        $this->customer = $this->entityManager->find(\App\Entity\Customer::class, $this->customer->getId());
        $this->address = $this->entityManager->find(\App\Entity\CustomerAddress::class, $this->address->getId());
    }

    private function page(string $url): Crawler
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @param array<string, string> $fields */
    private function submit(string $page, string $button, array $fields = []): void
    {
        $this->client->submit($this->page($page)->selectButton($button)->form($fields));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, mixed $id): object
    {
        $this->entityManager->clear();

        return $this->entityManager->find($class, $id);
    }
}
