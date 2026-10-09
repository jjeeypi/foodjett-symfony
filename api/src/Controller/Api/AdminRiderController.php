<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rider;
use App\Entity\RiderDocument;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/riders', name: 'api_admin_riders_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRiderController extends AbstractController
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
        $query = $this->entityManager->getRepository(Rider::class)->createQueryBuilder('rider')
            ->innerJoin('rider.user', 'owner')->addSelect('owner')
            ->orderBy('rider.createdAt', 'DESC');
        if ('' !== ($status = trim((string) $request->query->get('approval_status', '')))) {
            $approval = ApprovalStatus::tryFrom($status);
            if (null === $approval) {
                return $this->json(['message' => 'Invalid approval_status.'], 422);
            }
            $query->andWhere('rider.approvalStatus = :approval')->setParameter('approval', $approval->value);
        }
        if ('' !== ($search = trim((string) $request->query->get('search', '')))) {
            $query->andWhere('LOWER(owner.name) LIKE :search OR LOWER(COALESCE(rider.plateNumber, \'\')) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $this->json($this->paginator->paginate($query, $request, fn (Rider $rider): array => $this->normalize($rider)));
    }

    #[Route('/pending', name: 'pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Rider::class)->createQueryBuilder('rider')
            ->innerJoin('rider.user', 'owner')->addSelect('owner')
            ->andWhere('rider.approvalStatus = :status')
            ->setParameter('status', ApprovalStatus::PENDING->value)
            ->orderBy('rider.createdAt', 'ASC');

        return $this->json($this->paginator->paginate($query, $request, fn (Rider $rider): array => $this->normalize($rider, true)));
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(Rider $rider): JsonResponse
    {
        $before = $rider->getApprovalStatus();
        $rider->setApprovalStatus(ApprovalStatus::APPROVED)->setRejectionReason(null)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'rider.approved', 'rider', (string) $rider->getId(), [
            'approval_status' => ['from' => $before->value, 'to' => ApprovalStatus::APPROVED->value],
        ]);
        $this->entityManager->flush();

        return $this->json(['rider' => $this->normalize($rider, true)]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(Rider $rider, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $reason = trim(is_string($data['rejection_reason'] ?? null) ? $data['rejection_reason'] : '');
        if ('' === $reason || mb_strlen($reason) > 2000) {
            return $this->json(['message' => 'A rejection_reason of at most 2000 characters is required.'], 422);
        }
        $before = $rider->getApprovalStatus();
        $rider->setApprovalStatus(ApprovalStatus::REJECTED)->setRejectionReason($reason)->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'rider.rejected', 'rider', (string) $rider->getId(), [
            'approval_status' => ['from' => $before->value, 'to' => ApprovalStatus::REJECTED->value],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();

        return $this->json(['rider' => $this->normalize($rider, true)]);
    }

    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(Rider $rider): JsonResponse
    {
        return $this->setUserStatus($rider, UserStatus::SUSPENDED);
    }

    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(Rider $rider): JsonResponse
    {
        return $this->setUserStatus($rider, UserStatus::ACTIVE);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(Rider $rider, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $changes = [];
        if (array_key_exists('vehicle_type', $data)) {
            $vehicle = VehicleType::tryFrom((string) $data['vehicle_type']);
            if (null === $vehicle) {
                return $this->json(['message' => 'Invalid vehicle_type.'], 422);
            }
            $changes['vehicle_type'] = ['from' => $rider->getVehicleType()->value, 'to' => $vehicle->value];
            $rider->setVehicleType($vehicle);
        }
        if (array_key_exists('plate_number', $data)) {
            $plate = null === $data['plate_number'] ? null : trim((string) $data['plate_number']);
            if (null !== $plate && mb_strlen($plate) > 255) {
                return $this->json(['message' => 'plate_number is too long.'], 422);
            }
            $changes['plate_number'] = ['from' => $rider->getPlateNumber(), 'to' => $plate];
            $rider->setPlateNumber($plate);
        }
        if (array_key_exists('cash_remit_limit', $data)) {
            if (!is_numeric($data['cash_remit_limit']) || (float) $data['cash_remit_limit'] < 0) {
                return $this->json(['message' => 'cash_remit_limit must be a non-negative number.'], 422);
            }
            $limit = number_format((float) $data['cash_remit_limit'], 2, '.', '');
            $changes['cash_remit_limit'] = ['from' => $rider->getCashRemitLimit(), 'to' => $limit];
            $rider->setCashRemitLimit($limit);
        }
        if ([] === $changes) {
            return $this->json(['message' => 'No supported fields were supplied.'], 422);
        }
        $rider->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'rider.updated', 'rider', (string) $rider->getId(), $changes);
        $this->entityManager->flush();

        return $this->json(['rider' => $this->normalize($rider, true)]);
    }

    private function setUserStatus(Rider $rider, UserStatus $status): JsonResponse
    {
        $before = $rider->getUser()->getStatus();
        $rider->getUser()->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        if (UserStatus::SUSPENDED === $status) {
            $rider->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE)->setUpdatedAt(new \DateTimeImmutable());
        }
        $this->audit->record($this->admin(), 'rider.'.(UserStatus::ACTIVE === $status ? 'reactivated' : 'suspended'), 'rider', (string) $rider->getId(), [
            'user_status' => ['from' => $before->value, 'to' => $status->value],
        ]);
        $this->entityManager->flush();

        return $this->json(['rider' => $this->normalize($rider, true)]);
    }

    /** @return array<string, mixed> */
    private function normalize(Rider $rider, bool $withDocuments = false): array
    {
        $data = [
            'id' => $rider->getId(),
            'vehicle_type' => $rider->getVehicleType()->value,
            'plate_number' => $rider->getPlateNumber(),
            'approval_status' => $rider->getApprovalStatus()->value,
            'rejection_reason' => $rider->getRejectionReason(),
            'availability_status' => $rider->getAvailabilityStatus()->value,
            'cash_on_hand' => $rider->getCashOnHand(),
            'cash_remit_limit' => $rider->getCashRemitLimit(),
            'owner' => [
                'id' => $rider->getUser()->getId(),
                'name' => $rider->getUser()->getName(),
                'email' => $rider->getUser()->getEmail(),
                'phone' => $rider->getUser()->getPhone(),
                'status' => $rider->getUser()->getStatus()->value,
            ],
            'created_at' => $rider->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
        if ($withDocuments) {
            $data['documents'] = array_map(static fn (RiderDocument $document): array => [
                'id' => $document->getId(),
                'type' => $document->getType()->value,
                'file_path' => $document->getFilePath(),
                'status' => $document->getStatus()->value,
                'rejection_reason' => $document->getRejectionReason(),
            ], $rider->getDocuments()->toArray());
        }

        return $data;
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
