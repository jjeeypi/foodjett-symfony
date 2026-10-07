<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApprovalAccessTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const EMAILS = [
        'approval-pending-restaurant@foodjett.test',
        'approval-approved-restaurant@foodjett.test',
        'approval-pending-rider@foodjett.test',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->deleteTestUsers(self::getContainer()->get(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->deleteTestUsers($entityManager);
        $entityManager->close();

        parent::tearDown();
    }

    public function testPendingRestaurantCanSeeStatusButCannotUseFunctionalEndpoint(): void
    {
        $email = 'approval-pending-restaurant@foodjett.test';
        $this->createRestaurant($email, ApprovalStatus::PENDING);
        $token = $this->login($email);

        $this->client->request('GET', '/api/restaurant/approval-status', server: $this->auth($token));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/restaurant/dashboard', server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);
    }

    public function testApprovedRestaurantCanUseFunctionalEndpoint(): void
    {
        $email = 'approval-approved-restaurant@foodjett.test';
        $this->createRestaurant($email, ApprovalStatus::APPROVED);
        $token = $this->login($email);

        $this->client->request('GET', '/api/restaurant/dashboard', server: $this->auth($token));
        self::assertResponseIsSuccessful();
    }

    public function testPendingRiderCanSeeStatusButCannotUsePool(): void
    {
        $email = 'approval-pending-rider@foodjett.test';
        $this->createRider($email, ApprovalStatus::PENDING);
        $token = $this->login($email);

        $this->client->request('GET', '/api/rider/approval-status', server: $this->auth($token));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/rider/pool', server: $this->auth($token));
        self::assertResponseStatusCodeSame(403);
    }

    private function createRestaurant(string $email, ApprovalStatus $approvalStatus): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, UserRole::RESTAURANT);
        $restaurant = (new Restaurant())
            ->setUser($user)
            ->setName('Approval Test Restaurant')
            ->setSlug('approval-test-'.bin2hex(random_bytes(4)))
            ->setAddress('Test address')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus($approvalStatus)
            ->setOperatingStatus(RestaurantOperatingStatus::CLOSED)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->persist($user);
        $entityManager->persist($restaurant);
        $entityManager->flush();
    }

    private function createRider(string $email, ApprovalStatus $approvalStatus): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($email, UserRole::RIDER);
        $rider = (new Rider())
            ->setUser($user)
            ->setVehicleType(VehicleType::MOTORCYCLE)
            ->setPlateNumber('TEST-123')
            ->setApprovalStatus($approvalStatus)
            ->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->persist($user);
        $entityManager->persist($rider);
        $entityManager->flush();
    }

    private function createUser(string $email, UserRole $role): User
    {
        $now = new \DateTimeImmutable();

        return (new User())
            ->setName('Approval Test User')
            ->setEmail($email)
            ->setPhone('+639'.random_int(100000000, 999999999))
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function login(string $email): string
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'login' => $email,
            'password' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $payload['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function deleteTestUsers(EntityManagerInterface $entityManager): void
    {
        $users = $entityManager->getRepository(User::class)->findBy(['email' => self::EMAILS]);
        foreach ($users as $user) {
            if (null !== $user->getRestaurant()) {
                $entityManager->remove($user->getRestaurant());
            }
            if (null !== $user->getRider()) {
                $entityManager->remove($user->getRider());
            }
            $entityManager->remove($user);
        }
        $entityManager->flush();
    }
}
