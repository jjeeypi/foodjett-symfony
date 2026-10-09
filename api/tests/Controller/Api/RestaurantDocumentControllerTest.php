<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RestaurantDocument;
use App\Enum\DocumentStatus;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RestaurantDocumentControllerTest extends RestaurantApiTestCase
{
    public function testUploadCreatesPendingDocument(): void
    {
        $this->upload('business_permit');

        self::assertResponseStatusCodeSame(201);
        $documentData = $this->payload()['document'];
        $this->uploadedPaths[] = $documentData['file_path'];
        self::assertSame('business_permit', $documentData['type']);
        self::assertSame('pending', $documentData['status']);
        /** @var RestaurantDocument $document */
        $document = $this->fresh(RestaurantDocument::class, (string) $documentData['id']);
        self::assertSame($this->restaurant->getId(), $document->getRestaurant()->getId());
        self::assertSame(DocumentStatus::PENDING, $document->getStatus());
    }

    public function testRejectedDocumentCanBeReuploadedAndReturnsToPending(): void
    {
        $this->upload('food_safety_permit');
        self::assertResponseStatusCodeSame(201);
        $original = $this->payload()['document'];
        $this->uploadedPaths[] = $original['file_path'];
        /** @var RestaurantDocument $document */
        $document = $this->fresh(RestaurantDocument::class, (string) $original['id']);
        $document->setStatus(DocumentStatus::REJECTED)->setRejectionReason('Unreadable scan');
        $this->entityManager->flush();

        $this->client->request('POST', '/api/restaurant/documents/'.$document->getId(), files: ['file' => $this->pdf('replacement.pdf')], server: $this->auth());

        self::assertResponseIsSuccessful();
        $replacement = $this->payload()['document'];
        $this->uploadedPaths[] = $replacement['file_path'];
        self::assertNotSame($original['file_path'], $replacement['file_path']);
        self::assertSame('pending', $replacement['status']);
        self::assertNull($replacement['rejection_reason']);
        /** @var RestaurantDocument $fresh */
        $fresh = $this->fresh(RestaurantDocument::class, (string) $document->getId());
        self::assertSame(DocumentStatus::PENDING, $fresh->getStatus());
        self::assertNull($fresh->getRejectionReason());
    }

    public function testPendingAndVerifiedDocumentsCannotBeReuploaded(): void
    {
        $this->upload('owner_id');
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload()['document'];
        $this->uploadedPaths[] = $created['file_path'];
        /** @var RestaurantDocument $document */
        $document = $this->fresh(RestaurantDocument::class, (string) $created['id']);

        $this->client->request('POST', '/api/restaurant/documents/'.$document->getId(), files: ['file' => $this->pdf('pending.pdf')], server: $this->auth());
        self::assertResponseStatusCodeSame(409);

        /** @var RestaurantDocument $document */
        $document = $this->fresh(RestaurantDocument::class, (string) $document->getId());
        $document->setStatus(DocumentStatus::VERIFIED);
        $this->entityManager->flush();
        $this->client->request('POST', '/api/restaurant/documents/'.$document->getId(), files: ['file' => $this->pdf('verified.pdf')], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        /** @var RestaurantDocument $fresh */
        $fresh = $this->fresh(RestaurantDocument::class, (string) $document->getId());
        self::assertSame(DocumentStatus::VERIFIED, $fresh->getStatus());
    }

    private function upload(string $type): void
    {
        $this->client->request('POST', '/api/restaurant/documents', parameters: ['type' => $type], files: ['file' => $this->pdf($type.'.pdf')], server: $this->auth());
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'foodjett-pdf-');
        if (false === $path) {
            throw new \RuntimeException('Unable to create a temporary upload.');
        }
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
