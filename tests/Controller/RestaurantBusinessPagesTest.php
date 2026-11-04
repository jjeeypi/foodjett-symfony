<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\RestaurantPayout;
use App\Entity\RestaurantReview;
use App\Entity\Voucher;
use App\Tests\Controller\Api\RestaurantApiTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The restaurant's earnings, reviews and vouchers. */
class RestaurantBusinessPagesTest extends RestaurantApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->restaurantUser, 'main');
    }

    public function testEarningsMatchDeliveredOrdersForTodayWeekAndMonth(): void
    {
        $now = new \DateTimeImmutable();
        $samples = [[$now->modify('-5 minutes'), '100.00', '15.00'], [$now->modify('-2 days'), '200.00', '30.00'], [$now->modify('-10 days'), '300.00', '45.00']];
        foreach ($samples as [$deliveredAt, $subtotal, $commission]) {
            $this->createOrder($this->restaurant, Order::STATUS_DELIVERED, $subtotal, bcadd($subtotal, '35', 2), $deliveredAt, $deliveredAt, $commission);
        }
        $this->createOrder($this->restaurant, Order::STATUS_PLACED, '500.00', '535.00', $now);
        $this->createOrder($this->otherRestaurant, Order::STATUS_DELIVERED, '999.00', '1034.00', $now, $now, '149.85');
        $this->entityManager->flush();

        $crawler = $this->page('/restaurant/earnings');
        $rows = $crawler->filter('#periods tbody tr')->each(fn (Crawler $row) => $row->filter('td')->each(fn (Crawler $cell) => $cell->text()));
        $starts = ['Today' => $now->setTime(0, 0), 'This week' => $now->setTime(0, 0)->modify('monday this week'), 'This month' => $now->setTime(0, 0)->modify('first day of this month')];
        foreach ($rows as $row) {
            $included = array_filter($samples, static fn (array $sample): bool => $sample[0] >= $starts[$row[0]]);
            $gross = array_reduce($included, static fn (string $sum, array $sample): string => bcadd($sum, $sample[1], 2), '0.00');
            $commission = array_reduce($included, static fn (string $sum, array $sample): string => bcadd($sum, $sample[2], 2), '0.00');
            self::assertSame([$row[0], (string) count($included), 'PHP '.$gross, 'PHP '.$commission, 'PHP '.bcsub($gross, $commission, 2)], $row);
        }
    }

    public function testPayoutsAreScopedToTheRestaurant(): void
    {
        $now = new \DateTimeImmutable();
        $this->persist($this->payout($this->restaurant, '1000.00', $now), $this->payout($this->otherRestaurant, '2000.00', $now));
        $this->entityManager->flush();

        $crawler = $this->page('/restaurant/earnings');
        self::assertCount(1, $crawler->filter('#payouts tbody tr'));
        self::assertSelectorTextContains('#payouts', 'PHP 1000.00');
        self::assertSelectorTextNotContains('#payouts', 'PHP 2000.00');
    }

    public function testReviewsAreScopedAndRepliesAreSaved(): void
    {
        $now = new \DateTimeImmutable();
        $review = $this->review($this->restaurant, 5, 'Excellent', $now);
        $this->persist($review, $this->review($this->otherRestaurant, 2, 'Not for you', $now));
        $this->entityManager->flush();

        $crawler = $this->page('/restaurant/reviews');
        self::assertCount(1, $crawler->filter('article'));
        self::assertSelectorTextContains('#review-'.$review->getId(), 'Excellent');

        $this->client->submit($crawler->filter('#review-'.$review->getId())->selectButton('Reply')->form([
            'reply_'.$review->getId().'[restaurantReply]' => 'Thank you, come again!',
        ]));
        self::assertResponseRedirects('/restaurant/reviews');
        $saved = $this->fresh(RestaurantReview::class, (string) $review->getId());
        self::assertSame('Thank you, come again!', $saved->getRestaurantReply());
        self::assertNotNull($saved->getRepliedAt());

        $crawler = $this->page('/restaurant/reviews');
        $this->client->submit($crawler->filter('#review-'.$review->getId())->selectButton('Update reply')->form([
            'reply_'.$review->getId().'[restaurantReply]' => str_repeat('a', 5001),
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Write a reply first.');
        self::assertSame('Thank you, come again!', $this->fresh(RestaurantReview::class, (string) $review->getId())->getRestaurantReply());
    }

    public function testVouchersAreCreatedEditedValidatedAndDeleted(): void
    {
        $crawler = $this->page('/restaurant/vouchers/new');
        $this->client->submit($crawler->selectButton('Save')->form([
            'voucher[code]' => 'save10',
            'voucher[type]' => Voucher::TYPE_PERCENTAGE,
            'voucher[value]' => '10',
            'voucher[minOrderAmount]' => '200',
            'voucher[usageLimitPerCustomer]' => '2',
        ]));
        self::assertResponseRedirects('/restaurant/vouchers');
        $voucher = $this->entityManager->getRepository(Voucher::class)->findOneBy(['code' => 'SAVE10']);
        self::assertSame(Voucher::SCOPE_RESTAURANT, $voucher->getScope());
        self::assertSame((string) $this->restaurant->getId(), (string) $voucher->getRestaurant()->getId());
        self::assertSame('10.00', $voucher->getValue());

        $crawler = $this->page('/restaurant/vouchers/'.$voucher->getId().'/edit');
        $this->client->submit($crawler->selectButton('Update')->form(['voucher[value]' => '150']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A percentage cannot be more than 100.');

        $crawler = $this->page('/restaurant/vouchers/new');
        $this->client->submit($crawler->selectButton('Save')->form(['voucher[code]' => 'SAVE10', 'voucher[type]' => Voucher::TYPE_FREE_DELIVERY]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A voucher with that code already exists.');

        $this->client->submit($this->page('/restaurant/vouchers/'.$voucher->getId())->filter('form[action$="/restaurant/vouchers/'.$voucher->getId().'"]')->form());
        self::assertResponseRedirects('/restaurant/vouchers');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Voucher::class, $voucher->getId()));
    }

    public function testOtherRestaurantsAndPlatformVouchersAreOutOfReach(): void
    {
        $now = new \DateTimeImmutable();
        $foreign = (new Voucher())->setCode('THEIRS'.random_int(100, 999))->setScope(Voucher::SCOPE_RESTAURANT)
            ->setRestaurant($this->fresh(Restaurant::class, (string) $this->otherRestaurant->getId()))
            ->setType(Voucher::TYPE_FIXED)->setValue('20.00')->setIsActive(true)->setCreatedAt($now)->setUpdatedAt($now);
        $platform = (new Voucher())->setCode('PLATFORM'.random_int(100, 999))->setScope(Voucher::SCOPE_PLATFORM)
            ->setType(Voucher::TYPE_FIXED)->setValue('20.00')->setIsActive(true)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($foreign, $platform);
        $this->entityManager->flush();

        $crawler = $this->page('/restaurant/vouchers');
        self::assertCount(1, $crawler->filter('tbody tr'), 'Only the "no vouchers" row.');
        foreach ([$foreign, $platform] as $voucher) {
            $this->client->request('GET', '/restaurant/vouchers/'.$voucher->getId().'/edit');
            self::assertResponseStatusCodeSame(404);
        }
    }

    private function page(string $url): Crawler
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function payout(Restaurant $restaurant, string $gross, \DateTimeImmutable $now): RestaurantPayout
    {
        $commission = bcmul($gross, '0.15', 2);

        return (new RestaurantPayout())->setRestaurant($restaurant)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
            ->setGrossSales($gross)->setCommissionDeducted($commission)->setNetAmount(bcsub($gross, $commission, 2))
            ->setStatus(RestaurantPayout::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function review(Restaurant $restaurant, int $rating, string $comment, \DateTimeImmutable $now): RestaurantReview
    {
        $order = $this->createOrder($restaurant, Order::STATUS_DELIVERED, deliveredAt: $now);

        return (new RestaurantReview())->setOrder($order)->setCustomer($this->customer)->setRestaurant($restaurant)
            ->setRating($rating)->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
    }
}
