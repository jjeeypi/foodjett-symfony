<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RiderDocument;
use App\Enum\DocumentStatus;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RiderDocumentControllerTest extends RiderApiTestCase
{
    public function testDocumentUploadListsOwnDocumentAndOnlyRejectedCanBeReplaced(): void
    {
        $this->client->request('POST', '/api/rider/documents', ['type' => 'drivers_license'], ['file' => $this->imageUpload('license.png')], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        $first = $this->payload()['document'];
        self::assertSame('pending', $first['status']);
        self::assertSame('drivers_license', $first['type']);

        $this->client->request('GET', '/api/rider/documents', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['documents']);

        $this->client->request('POST', '/api/rider/documents', ['type' => 'drivers_license'], ['file' => $this->imageUpload('blocked.png')], server: $this->auth());
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Only rejected', $this->payload()['message']);

        $this->entityManager->getConnection()->executeStatement('UPDATE rider_documents SET status = ?, rejection_reason = ? WHERE id = ?', [DocumentStatus::REJECTED->value, 'Unreadable image', $first['id']]);
        $this->client->request('POST', '/api/rider/documents', ['type' => 'drivers_license'], ['file' => $this->imageUpload('replacement.png')], server: $this->auth());
        self::assertResponseIsSuccessful();
        $replacement = $this->payload()['document'];
        self::assertSame($first['id'], $replacement['id']);
        self::assertSame('pending', $replacement['status']);
        self::assertNull($replacement['rejection_reason']);
        self::assertNotSame($first['file_path'], $replacement['file_path']);
        $this->uploadedPaths[] = $replacement['file_path'];
    }

    public function testAnotherRidersDocumentsAreNeverListed(): void
    {
        $now = new \DateTimeImmutable();
        $foreign = (new RiderDocument())->setRider($this->otherRider)->setType(\App\Enum\RiderDocumentType::VALID_ID)
            ->setFilePath('uploads/riders/documents/foreign.pdf')->setStatus(DocumentStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($foreign);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/rider/documents', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['documents']);
    }

    private function imageUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'foodjett-rider-doc-');
        self::assertNotFalse($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
