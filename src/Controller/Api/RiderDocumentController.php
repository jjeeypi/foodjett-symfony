<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RiderDocument;
use App\Enum\DocumentStatus;
use App\Enum\RiderDocumentType;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider/documents', name: 'api_rider_documents_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderDocumentController extends AbstractRiderController
{
    private const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(EntityManagerInterface $entityManager, private readonly UploadStorage $uploads)
    {
        parent::__construct($entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $documents = $this->entityManager->getRepository(RiderDocument::class)->findBy(['rider' => $this->rider()], ['type' => 'ASC']);
        return $this->json(['documents' => array_map($this->documentData(...), $documents)]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $type = RiderDocumentType::tryFrom((string) $request->request->get('type', ''));
        $file = $request->files->get('file');
        if (null === $type || null === $file) {
            return $this->json(['message' => 'A valid document type and file are required.'], 422);
        }
        $document = $this->entityManager->getRepository(RiderDocument::class)->findOneBy(['rider' => $this->rider(), 'type' => $type]);
        if ($document instanceof RiderDocument && DocumentStatus::REJECTED !== $document->getStatus()) {
            return $this->json(['message' => 'This document type has already been submitted. Only rejected documents can be replaced.'], 409);
        }
        try { $path = $this->uploads->store($file, 'riders/documents', self::MIME_TYPES); }
        catch (\InvalidArgumentException $exception) { return $this->json(['message' => $exception->getMessage()], 422); }
        $oldPath = $document?->getFilePath();
        $now = new \DateTimeImmutable();
        $document ??= (new RiderDocument())->setRider($this->rider())->setType($type)->setCreatedAt($now);
        $document->setFilePath($path)->setStatus(DocumentStatus::PENDING)->setRejectionReason(null)->setUpdatedAt($now);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->uploads->delete($oldPath);

        return $this->json(['message' => 'Document submitted for review.', 'document' => $this->documentData($document)], null === $oldPath ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function documentData(RiderDocument $document): array
    {
        return ['id' => $document->getId(), 'type' => $document->getType()->value, 'file_path' => $document->getFilePath(),
            'status' => $document->getStatus()->value, 'rejection_reason' => $document->getRejectionReason(),
            'created_at' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updated_at' => $document->getUpdatedAt()?->format(\DateTimeInterface::ATOM)];
    }
}
