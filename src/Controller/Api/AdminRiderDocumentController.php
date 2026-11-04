<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RestaurantDocument;
use App\Entity\RiderDocument;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/rider-documents', name: 'api_admin_rider_documents_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRiderDocumentController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly AuditLogRepository $audit)
    {
    }

    #[Route('/{id}/verify', name: 'verify', methods: ['POST'])]
    public function verify(RiderDocument $document): JsonResponse
    {
        return $this->change($document, RestaurantDocument::STATUS_VERIFIED, null);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(RiderDocument $document, Request $request): JsonResponse
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

        return $this->change($document, RestaurantDocument::STATUS_REJECTED, $reason);
    }

    private function change(RiderDocument $document, string $status, ?string $reason): JsonResponse
    {
        $before = $document->getStatus();
        $document->setStatus($status)->setRejectionReason($reason)->setUpdatedAt(new \DateTimeImmutable());
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $this->audit->record($user, 'rider_document.'.(RiderDocument::STATUS_VERIFIED === $status ? 'verified' : 'rejected'), 'rider_document', (string) $document->getId(), [
            'status' => ['from' => $before, 'to' => $status],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();

        return $this->json(['document' => [
            'id' => $document->getId(),
            'rider_id' => $document->getRider()->getId(),
            'type' => $document->getType(),
            'file_path' => $document->getFilePath(),
            'status' => $document->getStatus(),
            'rejection_reason' => $document->getRejectionReason(),
        ]]);
    }
}
