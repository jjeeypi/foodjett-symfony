<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RestaurantReview;
use App\Entity\RiderReview;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/reviews', name: 'api_admin_reviews_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminReviewController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $type = trim((string) $request->query->get('type', 'all'));
        if (!in_array($type, ['all', 'restaurant', 'rider'], true)) { return $this->json(['message' => 'type must be all, restaurant, or rider.'], 422); }
        $records = [];
        if ('rider' !== $type) {
            foreach ($this->entityManager->getRepository(RestaurantReview::class)->findAll() as $review) { $records[] = $this->restaurant($review); }
        }
        if ('restaurant' !== $type) {
            foreach ($this->entityManager->getRepository(RiderReview::class)->findAll() as $review) { $records[] = $this->rider($review); }
        }
        usort($records, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('perPage', 25)));
        $total = count($records);

        return $this->json(['data' => array_slice($records, ($page - 1) * $perPage, $perPage), 'meta' => [
            'page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]]);
    }

    #[Route('/{type}/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $type, string $id): JsonResponse
    {
        $review = match ($type) {
            'restaurant' => $this->entityManager->find(RestaurantReview::class, $id),
            'rider' => $this->entityManager->find(RiderReview::class, $id),
            default => null,
        };
        if (!$review instanceof RestaurantReview && !$review instanceof RiderReview) { throw $this->createNotFoundException(); }
        $admin = $this->getUser();
        if (!$admin instanceof User) { throw $this->createAccessDeniedException(); }
        $this->audit->record($admin, 'review.deleted', $type.'_review', (string) $review->getId(), ['type' => $type, 'order_id' => $review->getOrder()->getId(), 'rating' => $review->getRating()]);
        $this->entityManager->remove($review);
        $this->entityManager->flush();
        return $this->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function restaurant(RestaurantReview $review): array
    {
        return ['id' => $review->getId(), 'type' => 'restaurant', 'order_id' => $review->getOrder()->getId(), 'order_number' => $review->getOrder()->getOrderNumber(),
            'customer_name' => $review->getCustomer()->getUser()->getName(), 'subject' => ['id' => $review->getRestaurant()->getId(), 'name' => $review->getRestaurant()->getName()],
            'rating' => $review->getRating(), 'comment' => $review->getComment(), 'photo_path' => $review->getPhotoPath(), 'reply' => $review->getRestaurantReply(),
            'created_at' => $review->getCreatedAt()?->format(\DateTimeInterface::ATOM)];
    }

    /** @return array<string, mixed> */
    private function rider(RiderReview $review): array
    {
        return ['id' => $review->getId(), 'type' => 'rider', 'order_id' => $review->getOrder()->getId(), 'order_number' => $review->getOrder()->getOrderNumber(),
            'customer_name' => $review->getCustomer()->getUser()->getName(), 'subject' => ['id' => $review->getRider()->getId(), 'name' => $review->getRider()->getUser()->getName()],
            'rating' => $review->getRating(), 'comment' => $review->getComment(), 'photo_path' => null, 'reply' => null,
            'created_at' => $review->getCreatedAt()?->format(\DateTimeInterface::ATOM)];
    }
}
