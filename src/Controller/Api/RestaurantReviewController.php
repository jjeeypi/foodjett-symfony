<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RestaurantReview;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/reviews', name: 'api_restaurant_reviews_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantReviewController extends AbstractRestaurantController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(RestaurantReview::class)->createQueryBuilder('review')
            ->andWhere('review.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->orderBy('review.createdAt', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, $this->serialize(...)));
    }

    #[Route('/{id}/reply', name: 'reply', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function reply(string $id, Request $request): JsonResponse
    {
        $review = $this->entityManager->getRepository(RestaurantReview::class)->createQueryBuilder('review')
            ->andWhere('review.id = :id')->setParameter('id', $id)
            ->andWhere('review.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
        if (!$review instanceof RestaurantReview) {
            throw $this->createNotFoundException('Review not found.');
        }
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $reply = trim(is_string($data['restaurant_reply'] ?? null) ? $data['restaurant_reply'] : (string) ($data['reply'] ?? ''));
        if ('' === $reply || mb_strlen($reply) > 5000) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => ['restaurant_reply' => ['A reply of at most 5000 characters is required.']]], 422);
        }
        $now = new \DateTimeImmutable();
        $review->setRestaurantReply($reply)->setRepliedAt($now)->setUpdatedAt($now);
        $this->entityManager->flush();

        return $this->json(['message' => 'Review reply saved.', 'review' => $this->serialize($review)]);
    }

    /** @return array<string, mixed> */
    private function serialize(RestaurantReview $review): array
    {
        return [
            'id' => $review->getId(), 'order_id' => $review->getOrder()->getId(), 'order_number' => $review->getOrder()->getOrderNumber(),
            'customer' => ['id' => $review->getCustomer()->getId(), 'name' => $review->getCustomer()->getUser()->getName()],
            'rating' => $review->getRating(), 'comment' => $review->getComment(), 'photo_path' => $review->getPhotoPath(),
            'restaurant_reply' => $review->getRestaurantReply(), 'replied_at' => $review->getRepliedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $review->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
