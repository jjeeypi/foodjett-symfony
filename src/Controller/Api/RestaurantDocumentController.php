<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Enum\DocumentStatus;
use App\Enum\RestaurantDocumentType;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/documents', name: 'api_restaurant_documents_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantDocumentController extends AbstractRestaurantController
{
    private const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UploadStorage $uploads,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $documents = $this->entityManager->getRepository(RestaurantDocument::class)->findBy(['restaurant' => $this->restaurant()], ['type' => 'ASC']);

        return $this->json(['documents' => array_map($this->serialize(...), $documents)]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant();
        $type = RestaurantDocumentType::tryFrom((string) $request->request->get('type', ''));
        $file = $request->files->get('file');
        $errors = [];
        if (null === $type) {
            $errors['type'][] = 'Type must be business_permit, food_safety_permit, or owner_id.';
        }
        if (null === $file) {
            $errors['file'][] = 'A document file is required.';
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }

        $document = $this->entityManager->getRepository(RestaurantDocument::class)->findOneBy(['restaurant' => $restaurant, 'type' => $type]);
        if ($document instanceof RestaurantDocument && DocumentStatus::REJECTED !== $document->getStatus()) {
            return $this->json(['message' => 'This document type has already been submitted. Only rejected documents can be replaced.'], 409);
        }

        return $this->saveUpload($restaurant, $document, $type, $file, 201);
    }

    #[Route('/{id}', name: 'replace', methods: ['PATCH', 'POST'], requirements: ['id' => '\\d+'])]
    public function replace(string $id, Request $request): JsonResponse
    {
        $document = $this->ownedDocument($id);
        if (DocumentStatus::REJECTED !== $document->getStatus()) {
            return $this->json(['message' => 'Only a rejected document can be re-uploaded.'], 409);
        }
        $file = $request->files->get('file');
        if (null === $file) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => ['file' => ['A document file is required.']]], 422);
        }

        return $this->saveUpload($this->restaurant(), $document, $document->getType(), $file);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(string $id): JsonResponse
    {
        $document = $this->ownedDocument($id);
        $path = $document->getFilePath();
        $this->entityManager->remove($document);
        $this->entityManager->flush();
        $this->uploads->delete($path);

        return $this->json(['message' => 'Document deleted.']);
    }

    private function saveUpload(Restaurant $restaurant, ?RestaurantDocument $document, RestaurantDocumentType $type, mixed $file, int $status = 200): JsonResponse
    {
        try {
            $path = $this->uploads->store($file, 'restaurants/documents', self::MIME_TYPES);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage(), 'errors' => ['file' => [$exception->getMessage()]]], 422);
        }

        $now = new \DateTimeImmutable();
        $oldPath = $document?->getFilePath();
        $document ??= (new RestaurantDocument())->setRestaurant($restaurant)->setType($type)->setCreatedAt($now);
        $document->setFilePath($path)->setStatus(DocumentStatus::PENDING)->setRejectionReason(null)->setUpdatedAt($now);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->uploads->delete($oldPath);

        return $this->json(['message' => 'Document submitted for review.', 'document' => $this->serialize($document)], $status);
    }

    private function ownedDocument(string $id): RestaurantDocument
    {
        $document = $this->entityManager->getRepository(RestaurantDocument::class)->createQueryBuilder('document')
            ->andWhere('document.id = :id')->setParameter('id', $id)
            ->andWhere('document.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
        if (!$document instanceof RestaurantDocument) {
            throw $this->createNotFoundException('Document not found.');
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private function serialize(RestaurantDocument $document): array
    {
        return [
            'id' => $document->getId(), 'type' => $document->getType()->value, 'file_path' => $document->getFilePath(),
            'status' => $document->getStatus()->value, 'rejection_reason' => $document->getRejectionReason(),
            'created_at' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $document->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
