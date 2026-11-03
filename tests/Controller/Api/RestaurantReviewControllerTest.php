<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RestaurantReview;
use App\Enum\OrderStatus;

final class RestaurantReviewControllerTest extends RestaurantApiTestCase
{
    public function testListOnlyReturnsAuthenticatedRestaurantsReviews(): void
    {
        $now = new \DateTimeImmutable();
        $own = $this->review($this->restaurant, 5, 'Excellent food', $now);
        $foreign = $this->review($this->otherRestaurant, 1, 'Not this restaurant', $now->modify('-1 minute'));
        $this->persist($own, $foreign);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/restaurant/reviews', server: $this->auth());

        self::assertResponseIsSuccessful();
        $ids = array_column($this->payload()['data'], 'id');
        self::assertSame([(string) $own->getId()], $ids);
        self::assertNotContains((string) $foreign->getId(), $ids);
    }

    public function testReplyPersistsTextAndTimestamp(): void
    {
        $review = $this->review($this->restaurant, 4, 'Tasty', new \DateTimeImmutable());
        $this->persist($review);
        $this->entityManager->flush();

        $this->client->jsonRequest('PATCH', '/api/restaurant/reviews/'.$review->getId().'/reply', [
            'restaurant_reply' => 'Thank you for ordering!',
        ], server: $this->auth());

        self::assertResponseIsSuccessful();
        self::assertSame('Thank you for ordering!', $this->payload()['review']['restaurant_reply']);
        self::assertNotNull($this->payload()['review']['replied_at']);
        /** @var RestaurantReview $fresh */
        $fresh = $this->fresh(RestaurantReview::class, (string) $review->getId());
        self::assertSame('Thank you for ordering!', $fresh->getRestaurantReply());
        self::assertNotNull($fresh->getRepliedAt());
    }

    public function testReplyLongerThanFiveThousandCharactersIsRejected(): void
    {
        $review = $this->review($this->restaurant, 3, 'Average', new \DateTimeImmutable());
        $this->persist($review);
        $this->entityManager->flush();

        $this->client->jsonRequest('PATCH', '/api/restaurant/reviews/'.$review->getId().'/reply', [
            'restaurant_reply' => str_repeat('x', 5001),
        ], server: $this->auth());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('restaurant_reply', $this->payload()['errors']);
        /** @var RestaurantReview $fresh */
        $fresh = $this->fresh(RestaurantReview::class, (string) $review->getId());
        self::assertNull($fresh->getRestaurantReply());
        self::assertNull($fresh->getRepliedAt());
    }

    private function review(\App\Entity\Restaurant $restaurant, int $rating, string $comment, \DateTimeImmutable $now): RestaurantReview
    {
        $order = $this->createOrder($restaurant, OrderStatus::DELIVERED, deliveredAt: $now);

        return (new RestaurantReview())->setOrder($order)->setCustomer($this->customer)->setRestaurant($restaurant)
            ->setRating($rating)->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
    }
}
