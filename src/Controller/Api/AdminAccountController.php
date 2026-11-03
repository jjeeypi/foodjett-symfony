<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Admin;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/admins', name: 'api_admin_admins_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogger $audit,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Admin::class)->createQueryBuilder('admin')
            ->innerJoin('admin.user', 'account')->addSelect('account')->orderBy('account.name', 'ASC');
        return $this->json($this->paginator->paginate($query, $request, fn (Admin $admin): array => $this->normalize($admin)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['message' => 'The request body must contain valid JSON.'], 400); }
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ('' === $name || mb_strlen($name) > 255) { return $this->json(['message' => 'name is required and cannot exceed 255 characters.'], 422); }
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) { return $this->json(['message' => 'A valid email is required.'], 422); }
        if ('' === $phone || mb_strlen($phone) > 255) { return $this->json(['message' => 'phone is required and cannot exceed 255 characters.'], 422); }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 4096) { return $this->json(['message' => 'password must be between 8 and 4096 characters.'], 422); }
        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) { return $this->json(['message' => 'A user with that email already exists.'], 409); }
        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['phone' => $phone])) { return $this->json(['message' => 'A user with that phone already exists.'], 409); }
        $actor = $this->adminUser();
        $now = new \DateTimeImmutable();
        $user = (new User())->setName($name)->setEmail($email)->setPhone($phone)->setRole(UserRole::ADMIN)->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $admin = (new Admin())->setUser($user)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($user);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->audit->record($actor, 'admin.created', 'admin', (string) $admin->getId(), ['name' => $name, 'email' => $email, 'phone' => $phone, 'status' => UserStatus::ACTIVE->value]);
        $this->entityManager->flush();
        return $this->json(['admin' => $this->normalize($admin)], 201);
    }

    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(Admin $admin): JsonResponse
    {
        $actor = $this->adminUser();
        if ($admin->getUser()->getId() === $actor->getId()) {
            return $this->json(['message' => 'You cannot suspend your own admin account.'], 409);
        }
        return $this->setStatus($admin, UserStatus::SUSPENDED, $actor);
    }

    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(Admin $admin): JsonResponse
    {
        return $this->setStatus($admin, UserStatus::ACTIVE, $this->adminUser());
    }

    private function setStatus(Admin $admin, UserStatus $status, User $actor): JsonResponse
    {
        $before = $admin->getUser()->getStatus();
        $admin->getUser()->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($actor, 'admin.'.(UserStatus::ACTIVE === $status ? 'reactivated' : 'suspended'), 'admin', (string) $admin->getId(), [
            'user_status' => ['from' => $before->value, 'to' => $status->value],
        ]);
        $this->entityManager->flush();
        return $this->json(['admin' => $this->normalize($admin)]);
    }

    /** @return array<string, mixed> */
    private function normalize(Admin $admin): array
    {
        return ['id' => $admin->getId(), 'user_id' => $admin->getUser()->getId(), 'name' => $admin->getUser()->getName(),
            'email' => $admin->getUser()->getEmail(), 'phone' => $admin->getUser()->getPhone(), 'status' => $admin->getUser()->getStatus()->value,
            'last_login_at' => $admin->getUser()->getLastLoginAt()?->format(\DateTimeInterface::ATOM), 'created_at' => $admin->getCreatedAt()?->format(\DateTimeInterface::ATOM)];
    }

    private function adminUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
