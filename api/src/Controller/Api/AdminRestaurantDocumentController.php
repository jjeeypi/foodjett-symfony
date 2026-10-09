<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RestaurantDocument;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/restaurant-documents', name: 'api_admin_restaurant_documents_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRestaurantDocumentController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly AuditLogger $audit)
    {
    }

    #[Route('/{id}/verify', name: 'verify', methods: ['POST'])]
    public function verify(RestaurantDocument $document): JsonResponse
    {
        return $this->change($document, DocumentStatus::VERIFIED, null);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(RestaurantDocument $document, Request $request): JsonResponse
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

        return $this->change($document, DocumentStatus::REJECTED, $reason);
    }

    private function change(RestaurantDocument $document, DocumentStatus $status, ?string $reason): JsonResponse
    {
        $before = $document->getStatus();
        $document->setStatus($status)->setRejectionReason($reason)->setUpdatedAt(new \DateTimeImmutable());
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $this->audit->record($user, 'restaurant_document.'.(DocumentStatus::VERIFIED === $status ? 'verified' : 'rejected'), 'restaurant_document', (string) $document->getId(), [
            'status' => ['from' => $before->value, 'to' => $status->value],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();

        return $this->json(['document' => [
            'id' => $document->getId(),
            'restaurant_id' => $document->getRestaurant()->getId(),
            'type' => $document->getType()->value,
            'file_path' => $document->getFilePath(),
            'status' => $document->getStatus()->value,
            'rejection_reason' => $document->getRejectionReason(),
        ]]);
    }
}
