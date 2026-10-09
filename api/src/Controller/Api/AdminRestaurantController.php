<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/restaurants', name: 'api_admin_restaurants_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRestaurantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Restaurant::class)->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->orderBy('restaurant.createdAt', 'DESC');
        if ('' !== ($status = trim((string) $request->query->get('approval_status', '')))) {
            $approval = ApprovalStatus::tryFrom($status);
            if (null === $approval) {
                return $this->json(['message' => 'Invalid approval_status.'], 422);
            }
            $query->andWhere('restaurant.approvalStatus = :approval')->setParameter('approval', $approval->value);
        }
        if ('' !== ($search = trim((string) $request->query->get('search', '')))) {
            $query->andWhere('LOWER(restaurant.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $this->json($this->paginator->paginate($query, $request, fn (Restaurant $restaurant): array => $this->normalize($restaurant)));
    }

    #[Route('/pending', name: 'pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Restaurant::class)->createQueryBuilder('restaurant')
            ->innerJoin('restaurant.user', 'owner')->addSelect('owner')
            ->andWhere('restaurant.approvalStatus = :status')
            ->setParameter('status', ApprovalStatus::PENDING->value)
            ->orderBy('restaurant.createdAt', 'ASC');

        return $this->json($this->paginator->paginate($query, $request, fn (Restaurant $restaurant): array => $this->normalize($restaurant, true)));
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(Restaurant $restaurant): JsonResponse
    {
        $before = $restaurant->getApprovalStatus();
        $restaurant->setApprovalStatus(ApprovalStatus::APPROVED)->setRejectionReason(null)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'restaurant.approved', 'restaurant', (string) $restaurant->getId(), [
            'approval_status' => ['from' => $before->value, 'to' => ApprovalStatus::APPROVED->value],
        ]);
        $this->entityManager->flush();

        return $this->json(['restaurant' => $this->normalize($restaurant, true)]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(Restaurant $restaurant, Request $request): JsonResponse
    {
        $reason = $this->requiredReason($request);
        if ($reason instanceof JsonResponse) {
            return $reason;
        }
        $before = $restaurant->getApprovalStatus();
        $restaurant->setApprovalStatus(ApprovalStatus::REJECTED)->setRejectionReason($reason)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'restaurant.rejected', 'restaurant', (string) $restaurant->getId(), [
            'approval_status' => ['from' => $before->value, 'to' => ApprovalStatus::REJECTED->value],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();

        return $this->json(['restaurant' => $this->normalize($restaurant, true)]);
    }

    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(Restaurant $restaurant): JsonResponse
    {
        return $this->setUserStatus($restaurant, UserStatus::SUSPENDED);
    }

    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(Restaurant $restaurant): JsonResponse
    {
        return $this->setUserStatus($restaurant, UserStatus::ACTIVE);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(Restaurant $restaurant, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $changes = [];
        foreach (['name' => 255, 'description' => 65535, 'cuisine_type' => 255, 'address' => 255] as $field => $max) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = null === $data[$field] ? null : trim((string) $data[$field]);
            if (in_array($field, ['name', 'address'], true) && (null === $value || '' === $value)) {
                return $this->json(['message' => $field.' cannot be blank.'], 422);
            }
            if (null !== $value && mb_strlen($value) > $max) {
                return $this->json(['message' => $field.' is too long.'], 422);
            }
            $getter = 'get'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
            $setter = 'set'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
            $before = $restaurant->{$getter}();
            $restaurant->{$setter}($value);
            $changes[$field] = ['from' => $before, 'to' => $value];
        }
        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180], 'commission_rate' => [0, 100], 'min_order_amount' => [0, 99999999]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (!is_numeric($data[$field]) || (float) $data[$field] < $min || (float) $data[$field] > $max) {
                return $this->json(['message' => $field.' is invalid.'], 422);
            }
            $setter = 'set'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
            $getter = 'get'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
            $value = in_array($field, ['latitude', 'longitude'], true)
                ? number_format((float) $data[$field], 7, '.', '')
                : number_format((float) $data[$field], 2, '.', '');
            $changes[$field] = ['from' => $restaurant->{$getter}(), 'to' => $value];
            $restaurant->{$setter}($value);
        }
        if (array_key_exists('default_prep_time_minutes', $data)) {
            $value = filter_var($data['default_prep_time_minutes'], FILTER_VALIDATE_INT);
            if (false === $value || $value < 1 || $value > 1440) {
                return $this->json(['message' => 'default_prep_time_minutes must be between 1 and 1440.'], 422);
            }
            $changes['default_prep_time_minutes'] = ['from' => $restaurant->getDefaultPrepTimeMinutes(), 'to' => $value];
            $restaurant->setDefaultPrepTimeMinutes($value);
        }
        if (array_key_exists('operating_status', $data)) {
            $status = RestaurantOperatingStatus::tryFrom((string) $data['operating_status']);
            if (null === $status) {
                return $this->json(['message' => 'Invalid operating_status.'], 422);
            }
            $changes['operating_status'] = ['from' => $restaurant->getOperatingStatus()->value, 'to' => $status->value];
            $restaurant->setOperatingStatus($status);
        }
        if ([] === $changes) {
            return $this->json(['message' => 'No supported fields were supplied.'], 422);
        }
        $restaurant->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'restaurant.updated', 'restaurant', (string) $restaurant->getId(), $changes);
        $this->entityManager->flush();

        return $this->json(['restaurant' => $this->normalize($restaurant, true)]);
    }

    private function setUserStatus(Restaurant $restaurant, UserStatus $status): JsonResponse
    {
        $before = $restaurant->getUser()->getStatus();
        $restaurant->getUser()->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'restaurant.'.(UserStatus::ACTIVE === $status ? 'reactivated' : 'suspended'), 'restaurant', (string) $restaurant->getId(), [
            'user_status' => ['from' => $before->value, 'to' => $status->value],
        ]);
        $this->entityManager->flush();

        return $this->json(['restaurant' => $this->normalize($restaurant, true)]);
    }

    /** @return array<string, mixed> */
    private function normalize(Restaurant $restaurant, bool $withDocuments = false): array
    {
        $data = [
            'id' => $restaurant->getId(),
            'name' => $restaurant->getName(),
            'description' => $restaurant->getDescription(),
            'cuisine_type' => $restaurant->getCuisineType(),
            'address' => $restaurant->getAddress(),
            'latitude' => $restaurant->getLatitude(),
            'longitude' => $restaurant->getLongitude(),
            'approval_status' => $restaurant->getApprovalStatus()->value,
            'rejection_reason' => $restaurant->getRejectionReason(),
            'operating_status' => $restaurant->getOperatingStatus()->value,
            'commission_rate' => $restaurant->getCommissionRate(),
            'min_order_amount' => $restaurant->getMinOrderAmount(),
            'default_prep_time_minutes' => $restaurant->getDefaultPrepTimeMinutes(),
            'owner' => [
                'id' => $restaurant->getUser()->getId(),
                'name' => $restaurant->getUser()->getName(),
                'email' => $restaurant->getUser()->getEmail(),
                'phone' => $restaurant->getUser()->getPhone(),
                'status' => $restaurant->getUser()->getStatus()->value,
            ],
            'created_at' => $restaurant->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
        if ($withDocuments) {
            $data['documents'] = array_map(static fn (RestaurantDocument $document): array => [
                'id' => $document->getId(),
                'type' => $document->getType()->value,
                'file_path' => $document->getFilePath(),
                'status' => $document->getStatus()->value,
                'rejection_reason' => $document->getRejectionReason(),
            ], $restaurant->getDocuments()->toArray());
        }

        return $data;
    }

    private function requiredReason(Request $request): string|JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $reason = trim(is_string($data['rejection_reason'] ?? null) ? $data['rejection_reason'] : '');

        return '' === $reason || mb_strlen($reason) > 2000
            ? $this->json(['message' => 'A rejection_reason of at most 2000 characters is required.'], 422)
            : $reason;
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
