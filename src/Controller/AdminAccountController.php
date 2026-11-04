<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\User;
use App\Form\AdminAccountFormType;
use App\Repository\AdminRepository;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Administrator accounts: adding admins and suspending or reactivating them, but never yourself. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/account')]
final class AdminAccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
        private readonly AdminRepository $admins,
    ) {
    }

    #[Route(name: 'app_admin_account', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderList();
    }

    #[Route('/new', name: 'app_admin_account_new', methods: ['POST'])]
    public function new(Request $request, #[CurrentUser] User $actor, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = new User();
        $form = $this->createForm(AdminAccountFormType::class, $user)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderList($form);
        }
        $now = new \DateTimeImmutable();
        /** @var string $plainPassword */
        $plainPassword = $form->get('plainPassword')->getData();
        $user->setName(trim($user->getName()))->setEmail(mb_strtolower(trim($user->getEmail())))->setPhone(trim($user->getPhone()))
            ->setRole(User::ACCOUNT_ADMIN)->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now)
            ->setPassword($passwordHasher->hashPassword($user, $plainPassword));
        $admin = (new Admin())->setUser($user)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($user);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->audit->record($actor, 'admin.created', 'admin', (string) $admin->getId(), [
            'name' => $user->getName(), 'email' => $user->getEmail(), 'phone' => $user->getPhone(), 'status' => User::STATUS_ACTIVE,
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $user->getName().' can now sign in as an admin.');

        return $this->redirectToRoute('app_admin_account', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/status/{status}', name: 'app_admin_account_status', methods: ['POST'], requirements: ['id' => '\d+', 'status' => 'suspended|active'])]
    public function status(Request $request, Admin $admin, string $status, #[CurrentUser] User $actor): Response
    {
        if (!$this->isCsrfTokenValid('admin'.$admin->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (User::STATUS_SUSPENDED === $status && $admin->getUser()->getId() === $actor->getId()) {
            $this->addFlash('error', 'You cannot suspend your own admin account.');

            return $this->redirectToRoute('app_admin_account', [], Response::HTTP_SEE_OTHER);
        }
        $before = $admin->getUser()->getStatus();
        $admin->getUser()->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($actor, 'admin.'.(User::STATUS_ACTIVE === $status ? 'reactivated' : 'suspended'), 'admin', (string) $admin->getId(), [
            'user_status' => ['from' => $before, 'to' => $status],
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $admin->getUser()->getName().(User::STATUS_ACTIVE === $status ? ' was reactivated.' : ' was suspended.'));

        return $this->redirectToRoute('app_admin_account', [], Response::HTTP_SEE_OTHER);
    }

    private function renderList(?FormInterface $form = null): Response
    {
        return $this->render('admin_account/index.html.twig', [
            'admins' => $this->admins->createListQuery()->getQuery()->getResult(),
            'form' => $form ?? $this->createForm(AdminAccountFormType::class, new User()),
        ], new Response(status: null !== $form ? 422 : 200));
    }
}
