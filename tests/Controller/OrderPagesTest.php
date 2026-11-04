<?php

namespace App\Tests\Controller;

use App\Entity\CartItem;
use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\RestaurantReview;
use App\Entity\RiderReview;

/** The customer's order list and order page: detail, rider visibility, reorder, reviews and reports. */
class OrderPagesTest extends CustomerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->customerUser, 'main');
    }

    public function testListFiltersOwnOrdersAndDetailShowsTheTimeline(): void
    {
        $active = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_PLACED, new \DateTimeImmutable('-1 minute'));
        $past = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED, new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'));
        $foreign = $this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress);
        $history = (new OrderStatusHistory())->setOrder($active)->setStatus(Order::STATUS_PLACED)->setChangedBy(Order::ACTOR_SYSTEM)
            ->setNote('Placed by checkout')->setCreatedAt(new \DateTimeImmutable('-1 minute'));
        $active->addStatusHistory($history);
        $this->persist($history);
        $this->entityManager->flush();

        self::assertSame([$active->getOrderNumber()], $this->listed('/orders?filter=active'));
        self::assertSame([$past->getOrderNumber()], $this->listed('/orders?filter=past'));
        self::assertSame([$active->getOrderNumber(), $past->getOrderNumber()], $this->listed('/orders'));

        $this->entityManager->clear();
        $this->client->request('GET', '/orders/'.$active->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $active->getOrderNumber());
        self::assertSelectorTextContains('#timeline', 'placed · Placed by checkout');
        self::assertSelectorExists('form[name="order_cancel_form"]');

        // The page follows its own order live; the hub cookie lets it subscribe to that topic only.
        $live = $this->client->getCrawler()->filter('#live');
        self::assertSame('orders/'.$active->getId().'/status', $live->attr('data-symfony--ux-turbo--mercure-turbo-stream-topic-value'));
        self::assertSame('true', $live->attr('data-symfony--ux-turbo--mercure-turbo-stream-with-credentials-value'));
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertSame('mercure_access_token', $cookie?->getName());
        $claims = json_decode(base64_decode(strtr(explode('.', (string) $cookie->getValue())[1], '-_', '+/')), true);
        self::assertSame([['match' => 'orders/'.$active->getId().'/status']], $claims['authorization_details'][0]['topics']);
        self::assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);

        $this->client->request('GET', '/orders/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRiderLocationIsShownDuringDeliveryOnly(): void
    {
        $rider = $this->createRider()->setCurrentLatitude('14.6012345')->setCurrentLongitude('120.9876543')
            ->setLastLocationAt(new \DateTimeImmutable('-10 seconds'));
        $enRoute = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_RIDER_ASSIGNED)
            ->setRider($rider)->setAcceptedAt(new \DateTimeImmutable('-20 minutes'))->setRiderAssignedAt(new \DateTimeImmutable('-2 minutes'));
        $delivered = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED,
            new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('-1 minute'))->setRider($rider);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request('GET', '/orders/'.$enRoute->getId());
        self::assertSelectorTextContains('#rider', 'motorcycle');
        self::assertSelectorTextContains('#rider', '14.6012345, 120.9876543');
        self::assertSelectorNotExists('form[name="order_cancel_form"]', 'Accepted orders cannot be cancelled by the customer.');

        $this->client->request('GET', '/orders/'.$delivered->getId());
        self::assertSelectorTextContains('#rider', 'motorcycle');
        self::assertSelectorTextNotContains('#rider', '14.6012345');
    }

    public function testReorderFillsTheCartAndRefusesUnavailableItems(): void
    {
        $menuItem = $this->createItem($this->createCategory($this->restaurant), price: '175.50');
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED,
            new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'));
        $orderItem = (new OrderItem())->setOrder($order)->setMenuItem($menuItem)->setQuantity(2)->setUnitPrice('99.00')
            ->setSpecialInstructions('Less salt')->setCreatedAt(new \DateTimeImmutable('-2 days'))->setUpdatedAt(new \DateTimeImmutable('-2 days'));
        $order->addItem($orderItem);
        $this->persist($orderItem);
        $this->entityManager->flush();

        $this->reorder($order);
        self::assertResponseRedirects('/cart');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your cart now has the items from order '.$order->getOrderNumber());
        // Current price, not the 99.00 paid back then.
        self::assertSelectorTextContains('#subtotal', '₱351.00');
        $lines = $this->entityManager->getRepository(CartItem::class)->findBy(['customer' => $this->customer->getId()]);
        self::assertCount(1, $lines);
        self::assertSame('Less salt', $lines[0]->getSpecialInstructions());

        $this->fresh(MenuItem::class, (string) $menuItem->getId())->setIsAvailable(false);
        $this->entityManager->flush();
        $this->reorder($order);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'no longer available');
    }

    public function testDeliveredOrdersCanBeReviewedOnceWithoutAnExpiry(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED,
            new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'))->setRider($this->createRider());
        $oldOrder = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED,
            new \DateTimeImmutable('-31 days'), new \DateTimeImmutable('-30 days'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Send review')->form([
            'order_review_form[restaurantRating]' => '5',
            'order_review_form[riderRating]' => '4',
            'order_review_form[comment]' => 'Great meal and delivery.',
        ]));
        self::assertResponseRedirects('/orders/'.$order->getId());
        self::assertSame(1, $this->entityManager->getRepository(RestaurantReview::class)->count(['order' => $order->getId()]));
        self::assertSame(1, $this->entityManager->getRepository(RiderReview::class)->count(['order' => $order->getId()]));

        $this->entityManager->clear();
        $this->client->followRedirect();
        self::assertSelectorNotExists('form[name="order_review_form"]', 'Nothing is left to review.');

        $crawler = $this->client->request('GET', '/orders/'.$oldOrder->getId());
        $this->client->submit($crawler->selectButton('Send review')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="order_review_form"]', 'Give at least one rating.');
        $this->client->submit($crawler->selectButton('Send review')->form(['order_review_form[restaurantRating]' => '5']));
        self::assertResponseRedirects('/orders/'.$oldOrder->getId());
    }

    public function testReportsAreAllowedWhileActiveOrUpToSevenDaysAfterDelivery(): void
    {
        $active = $this->createOrder($this->customer, $this->restaurant, $this->address, placedAt: new \DateTimeImmutable('-60 days'));
        $recent = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED,
            new \DateTimeImmutable('-6 days'), new \DateTimeImmutable('-5 days'));
        $old = $this->createOrder($this->customer, $this->restaurant, $this->address, Order::STATUS_DELIVERED,
            new \DateTimeImmutable('-11 days'), new \DateTimeImmutable('-10 days'));
        $foreign = $this->createOrder($this->otherCustomer, $this->otherRestaurant, $this->otherAddress);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->report($active, 'missing_item', 'restaurant', 'One item was missing.');
        self::assertResponseRedirects('/orders/'.$active->getId());
        $this->report($recent, 'late_delivery', 'rider', 'The delivery arrived very late.');
        self::assertResponseRedirects('/orders/'.$recent->getId());
        $reports = $this->entityManager->getRepository(OrderReport::class)->findBy(['status' => OrderReport::STATUS_OPEN]);
        self::assertCount(2, $reports);

        $crawler = $this->client->request('GET', '/orders/'.$old->getId());
        self::assertCount(0, $crawler->filter('form[name="order_report_form"]'));

        $this->client->request('GET', '/orders/'.$foreign->getId());
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/orders/'.$active->getId());
        $this->client->submit($crawler->selectButton('Send report')->form(['order_report_form[description]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="order_report_form"]', 'Describe the problem.');
    }

    /** @return list<string> */
    private function listed(string $url): array
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('tbody td:first-child a')->each(fn ($link) => $link->text());
    }

    private function reorder(Order $order): void
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Order these items again')->form());
    }

    private function report(Order $order, string $type, string $against, string $description): void
    {
        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Send report')->form([
            'order_report_form[type]' => $type,
            'order_report_form[against]' => $against,
            'order_report_form[description]' => $description,
        ]));
    }
}
