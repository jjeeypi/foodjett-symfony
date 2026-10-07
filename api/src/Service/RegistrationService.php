<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Auth\CustomerRegistrationRequest;
use App\Dto\Auth\RestaurantRegistrationRequest;
use App\Dto\Auth\RiderRegistrationRequest;
use App\Entity\Customer;
use App\Entity\PlatformSetting;
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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final readonly class RegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private SluggerInterface $slugger,
    ) {
    }

    public function registerCustomer(CustomerRegistrationRequest $request): User
    {
        return $this->entityManager->wrapInTransaction(function () use ($request): User {
            $now = new \DateTimeImmutable();
            $user = $this->newUser($request->name, $request->email, $request->phone, $request->password, UserRole::CUSTOMER, $now);
            $customer = (new Customer())
                ->setUser($user)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->entityManager->persist($user);
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            return $user;
        });
    }

    public function registerRestaurant(RestaurantRegistrationRequest $request): User
    {
        return $this->entityManager->wrapInTransaction(function () use ($request): User {
            $now = new \DateTimeImmutable();
            $user = $this->newUser($request->ownerName, $request->email, $request->phone, $request->password, UserRole::RESTAURANT, $now);
            $setting = $this->entityManager->getRepository(PlatformSetting::class)->findOneBy(['key' => 'default_commission_rate']);
            $commissionRate = $setting?->getValue() ?? '15.00';
            $restaurant = (new Restaurant())
                ->setUser($user)
                ->setName($request->restaurantName)
                ->setSlug($this->uniqueRestaurantSlug($request->restaurantName))
                ->setAddress($request->address)
                ->setLatitude($request->latitude)
                ->setLongitude($request->longitude)
                ->setCuisineType($request->cuisineType)
                ->setCommissionRate(number_format((float) $commissionRate, 2, '.', ''))
                ->setApprovalStatus(ApprovalStatus::PENDING)
                ->setOperatingStatus(RestaurantOperatingStatus::CLOSED)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->entityManager->persist($user);
            $this->entityManager->persist($restaurant);
            $this->entityManager->flush();

            return $user;
        });
    }

    public function registerRider(RiderRegistrationRequest $request): User
    {
        return $this->entityManager->wrapInTransaction(function () use ($request): User {
            $now = new \DateTimeImmutable();
            $user = $this->newUser($request->name, $request->email, $request->phone, $request->password, UserRole::RIDER, $now);
            $rider = (new Rider())
                ->setUser($user)
                ->setVehicleType(VehicleType::from($request->vehicleType))
                ->setPlateNumber($request->plateNumber)
                ->setApprovalStatus(ApprovalStatus::PENDING)
                ->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            $this->entityManager->persist($user);
            $this->entityManager->persist($rider);
            $this->entityManager->flush();

            return $user;
        });
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function phoneExists(string $phone): bool
    {
        return null !== $this->entityManager->getRepository(User::class)->findOneBy(['phone' => trim($phone)]);
    }

    private function newUser(string $name, string $email, string $phone, string $password, UserRole $role, \DateTimeImmutable $now): User
    {
        $user = (new User())
            ->setName(trim($name))
            ->setEmail(mb_strtolower(trim($email)))
            ->setPhone(trim($phone))
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        return $user->setPassword($this->passwordHasher->hashPassword($user, $password));
    }

    private function uniqueRestaurantSlug(string $name): string
    {
        $base = mb_strtolower((string) $this->slugger->slug($name));
        $base = '' !== $base ? $base : 'restaurant';
        $slug = $base;
        $suffix = 2;

        while (null !== $this->entityManager->getRepository(Restaurant::class)->findOneBy(['slug' => $slug])) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
