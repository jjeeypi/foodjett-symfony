<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\Rider;
use App\Entity\RiderDocument;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\DocumentStatus;
use App\Enum\RestaurantDocumentType;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\RiderDocumentType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminApprovalControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'approval-admin@foodjett.test';
    private const RESTAURANT_EMAIL = 'approval-restaurant@foodjett.test';
    private const RIDER_EMAIL = 'approval-rider@foodjett.test';

    private KernelBrowser $client;
    private string $restaurantId;
    private string $restaurantDocumentId;
    private string $riderId;
    private string $riderDocumentId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        [$this->restaurantId, $this->restaurantDocumentId, $this->riderId, $this->riderDocumentId] = $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testPendingQueuesIncludeDocumentsAndSupportSearch(): void
    {
        $token = $this->login();
        $this->client->request('GET', '/api/admin/restaurants/pending?perPage=10', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $restaurant = $this->find($this->payload()['data'] ?? [], $this->restaurantId);
        self::assertSame('Approval Restaurant', $restaurant['name']);
        self::assertSame('business_permit', $restaurant['documents'][0]['type']);

        $this->client->request('GET', '/api/admin/riders?search=Approval Rider&approval_status=pending', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $rider = $this->find($this->payload()['data'] ?? [], $this->riderId);
        self::assertSame('Approval Rider', $rider['owner']['name']);
        self::assertSame(1, $this->payload()['meta']['total']);
    }

    public function testRestaurantApprovalRejectionAndSuspensionAreAudited(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/restaurants/'.$this->restaurantId.'/reject', [], server: $this->auth($token));
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/admin/restaurants/'.$this->restaurantId.'/reject', ['rejection_reason' => 'Permit is unreadable.'], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['restaurant']['approval_status']);

        $this->client->jsonRequest('POST', '/api/admin/restaurants/'.$this->restaurantId.'/approve', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['restaurant']['rejection_reason']);

        $this->client->jsonRequest('POST', '/api/admin/restaurants/'.$this->restaurantId.'/suspend', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['restaurant']['owner']['status']);

        $this->client->jsonRequest('POST', '/api/admin/restaurants/'.$this->restaurantId.'/reactivate', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['restaurant']['owner']['status']);

        $actions = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditLog::class)
            ->createQueryBuilder('log')->select('log.action')->andWhere('log.subjectId = :id')->setParameter('id', $this->restaurantId)->getQuery()->getSingleColumnResult();
        self::assertContains('restaurant.rejected', $actions);
        self::assertContains('restaurant.approved', $actions);
        self::assertContains('restaurant.suspended', $actions);
        self::assertContains('restaurant.reactivated', $actions);
    }

    public function testRiderRejectionAndSuspensionForceOfflineAndAreAudited(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/riders/'.$this->riderId.'/reject', ['rejection_reason' => 'ID mismatch.'], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['rider']['approval_status']);
        self::assertSame('offline', $this->payload()['rider']['availability_status']);

        $this->client->jsonRequest('POST', '/api/admin/riders/'.$this->riderId.'/approve', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/api/admin/riders/'.$this->riderId.'/suspend', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['rider']['owner']['status']);
        self::assertSame('offline', $this->payload()['rider']['availability_status']);

        $actions = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditLog::class)
            ->createQueryBuilder('log')->select('log.action')->andWhere('log.subjectId = :id')->setParameter('id', $this->riderId)->getQuery()->getSingleColumnResult();
        self::assertContains('rider.rejected', $actions);
        self::assertContains('rider.approved', $actions);
        self::assertContains('rider.suspended', $actions);
    }

    public function testDocumentReviewIsIndependentFromOverallApproval(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/restaurant-documents/'.$this->restaurantDocumentId.'/verify', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('verified', $this->payload()['document']['status']);

        $this->client->jsonRequest('POST', '/api/admin/rider-documents/'.$this->riderDocumentId.'/reject', ['rejection_reason' => 'Expired license.'], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['document']['status']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(ApprovalStatus::PENDING, $entityManager->find(Restaurant::class, $this->restaurantId)?->getApprovalStatus());
        self::assertSame(ApprovalStatus::PENDING, $entityManager->find(Rider::class, $this->riderId)?->getApprovalStatus());
    }

    /** @return array{string, string, string, string} */
    private function createFixture(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Approval Admin', self::ADMIN_EMAIL, '+639190100001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Restaurant Owner', self::RESTAURANT_EMAIL, '+639190100002', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)->setName('Approval Restaurant')->setSlug('approval-restaurant')
            ->setCuisineType('Filipino')->setAddress('Approval Street')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::PENDING)->setOperatingStatus(RestaurantOperatingStatus::CLOSED)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantDocument = (new RestaurantDocument())->setRestaurant($restaurant)->setType(RestaurantDocumentType::BUSINESS_PERMIT)
            ->setFilePath('documents/test-permit.pdf')->setStatus(DocumentStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $riderUser = $this->user('Approval Rider', self::RIDER_EMAIL, '+639190100003', UserRole::RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(VehicleType::MOTORCYCLE)->setPlateNumber('TEST-APPROVAL')
            ->setApprovalStatus(ApprovalStatus::PENDING)->setAvailabilityStatus(RiderAvailabilityStatus::AVAILABLE)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $riderDocument = (new RiderDocument())->setRider($rider)->setType(RiderDocumentType::DRIVERS_LICENSE)
            ->setFilePath('documents/test-license.pdf')->setStatus(DocumentStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $restaurantUser, $restaurant, $restaurantDocument, $riderUser, $rider, $riderDocument] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [(string) $restaurant->getId(), (string) $restaurantDocument->getId(), (string) $rider->getId(), (string) $riderDocument->getId()];
    }

    private function user(string $name, string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))->setRole($role)->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }

    /** @param list<array<string, mixed>> $records */
    private function find(array $records, string $id): array
    {
        foreach ($records as $record) {
            if ((string) ($record['id'] ?? '') === $id) {
                return $record;
            }
        }
        self::fail('Expected record '.$id.' was not returned.');
    }

    private function login(): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        return (string) $this->payload()['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array { return ['HTTP_AUTHORIZATION' => 'Bearer '.$token]; }

    /** @return array<string, mixed> */
    private function payload(): array { return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM audit_logs WHERE action LIKE 'restaurant.%' OR action LIKE 'rider.%' OR action LIKE 'restaurant_document.%' OR action LIKE 'rider_document.%'");
        $connection->executeStatement('DELETE FROM restaurant_documents WHERE restaurant_id IN (SELECT id FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?))', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM rider_documents WHERE rider_id IN (SELECT id FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?))', [self::RIDER_EMAIL]);
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::ADMIN_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RIDER_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?)', [self::ADMIN_EMAIL, self::RESTAURANT_EMAIL, self::RIDER_EMAIL]);
    }
}
