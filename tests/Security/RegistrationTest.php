<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\DocumentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\UserRole;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RegistrationTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const EMAILS = [
        'register-customer@foodjett.test',
        'register-restaurant@foodjett.test',
        'register-rider@foodjett.test',
        'register-validation@foodjett.test',
        'register-bicycle@foodjett.test',
        'register-missing-doc@foodjett.test',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->deleteTestUsers();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUsers();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->close();

        parent::tearDown();
    }

    public function testCustomerRegistrationCreatesProfileAndReturnsJwt(): void
    {
        $email = 'register-customer@foodjett.test';
        $this->client->jsonRequest('POST', '/api/register', [
            'name' => 'Customer Test',
            'email' => $email,
            'phone' => '+639181111111',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload();
        self::assertNotEmpty($payload['token'] ?? null);
        self::assertSame(UserRole::CUSTOMER->value, $payload['user']['role'] ?? null);
        self::assertNull($payload['user']['approval_status'] ?? null);

        $user = $this->user($email);
        self::assertNotNull($user->getCustomer());
    }

    public function testRestaurantRegistrationCreatesPendingClosedProfile(): void
    {
        $email = 'register-restaurant@foodjett.test';
        $this->client->request('POST', '/api/register/restaurant', [
            'owner_name' => 'Restaurant Owner',
            'email' => $email,
            'phone' => '+639182222222',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'restaurant_name' => 'Registration Test Kitchen',
            'address' => '123 Test Street',
            'latitude' => '14.5995',
            'longitude' => '120.9842',
            'cuisine_type' => 'Filipino',
        ], $this->documentUploads(['business_permit', 'food_safety_permit', 'owner_id']));

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload();
        self::assertArrayNotHasKey('token', $payload);
        self::assertSame(ApprovalStatus::PENDING->value, $payload['user']['approval_status'] ?? null);

        $restaurant = $this->user($email)->getRestaurant();
        self::assertNotNull($restaurant);
        self::assertSame(RestaurantOperatingStatus::CLOSED, $restaurant->getOperatingStatus());
        self::assertSame('15.00', $restaurant->getCommissionRate());
        self::assertCount(3, $restaurant->getDocuments());
        foreach ($restaurant->getDocuments() as $document) {
            self::assertSame(DocumentStatus::PENDING, $document->getStatus());
            self::assertStringStartsWith('uploads/restaurants/documents/', $document->getFilePath());
        }
    }

    public function testRiderRegistrationCreatesPendingOfflineProfile(): void
    {
        $email = 'register-rider@foodjett.test';
        $this->client->request('POST', '/api/register/rider', [
            'name' => 'Rider Test',
            'email' => $email,
            'phone' => '+639183333333',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'REG-1234',
        ], $this->documentUploads(['drivers_license', 'vehicle_registration', 'valid_id', 'police_clearance']));

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload();
        self::assertArrayNotHasKey('token', $payload);
        self::assertSame(ApprovalStatus::PENDING->value, $payload['user']['approval_status'] ?? null);

        $rider = $this->user($email)->getRider();
        self::assertNotNull($rider);
        self::assertSame(RiderAvailabilityStatus::OFFLINE, $rider->getAvailabilityStatus());
        self::assertCount(4, $rider->getDocuments());
        foreach ($rider->getDocuments() as $document) {
            self::assertSame(DocumentStatus::PENDING, $document->getStatus());
            self::assertStringStartsWith('uploads/riders/documents/', $document->getFilePath());
        }
    }

    public function testRegistrationValidatesConfirmationUniquenessAndConditionalPlate(): void
    {
        $customerEmail = 'register-validation@foodjett.test';
        $this->client->jsonRequest('POST', '/api/register', [
            'name' => 'Validation Test',
            'email' => $customerEmail,
            'phone' => '+639184444444',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/register/rider', [
            'name' => 'Invalid Rider',
            'email' => $customerEmail,
            'phone' => '+639184444444',
            'password' => self::PASSWORD,
            'password_confirmation' => 'DoesNotMatch123!',
            'vehicle_type' => 'motorcycle',
            'plate_number' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertArrayHasKey('email', $payload['errors']);
        self::assertArrayHasKey('phone', $payload['errors']);
        self::assertArrayHasKey('password_confirmation', $payload['errors']);
        self::assertArrayHasKey('plate_number', $payload['errors']);
    }

    public function testBicycleDoesNotRequirePlateNumber(): void
    {
        $email = 'register-bicycle@foodjett.test';
        $this->client->request('POST', '/api/register/rider', [
            'name' => 'Bicycle Rider',
            'email' => $email,
            'phone' => '+639185555555',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'vehicle_type' => 'bicycle',
        ], $this->documentUploads(['drivers_license', 'vehicle_registration', 'valid_id', 'police_clearance']));

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->user($email)->getRider()?->getPlateNumber());
    }

    public function testRoleApplicationRequiresEveryDocumentBeforeCreatingAccount(): void
    {
        $email = 'register-missing-doc@foodjett.test';
        $this->client->request('POST', '/api/register/restaurant', [
            'owner_name' => 'Missing Document Owner',
            'email' => $email,
            'phone' => '+639186666666',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'restaurant_name' => 'Incomplete Kitchen',
            'address' => '456 Test Street',
            'latitude' => '14.5995',
            'longitude' => '120.9842',
            'cuisine_type' => 'Filipino',
        ], $this->documentUploads(['business_permit', 'food_safety_permit']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('This document is required.', $this->payload()['errors']['owner_id'][0] ?? null);
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(string $email): User
    {
        $user = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function deleteTestUsers(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $uploads = self::getContainer()->get(UploadStorage::class);
        $users = $entityManager->getRepository(User::class)->findBy(['email' => self::EMAILS]);
        foreach ($users as $user) {
            foreach ([$user->getCustomer(), $user->getRestaurant(), $user->getRider()] as $profile) {
                if (null !== $profile) {
                    if (method_exists($profile, 'getDocuments')) {
                        foreach ($profile->getDocuments() as $document) {
                            $uploads->delete($document->getFilePath());
                        }
                    }
                    $entityManager->remove($profile);
                }
            }
            $entityManager->remove($user);
        }
        $entityManager->flush();
    }

    /**
     * @param list<string> $types
     *
     * @return array<string, UploadedFile>
     */
    private function documentUploads(array $types): array
    {
        $files = [];
        foreach ($types as $type) {
            $path = tempnam(sys_get_temp_dir(), 'foodjett-registration-doc-');
            self::assertNotFalse($path);
            file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
            $files[$type] = new UploadedFile($path, $type.'.pdf', 'application/pdf', null, true);
        }

        return $files;
    }
}
