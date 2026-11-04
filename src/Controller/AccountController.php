<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The customer's profile, with links to addresses and support, and account deletion. */
#[IsGranted('ROLE_CUSTOMER')]
#[Route('/account')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
    ) {
    }

    #[Route('', name: 'app_account', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $previousEmail = $user->getEmail();
        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setName(trim($user->getName()))->setUpdatedAt(new \DateTimeImmutable());
            if ($user->getEmail() !== $previousEmail) {
                $user->setEmailVerifiedAt(null);
            }
            $avatar = $form->get('avatar')->getData();
            if ($avatar instanceof UploadedFile) {
                $this->deletePublicFile($user->getAvatarPath());
                $user->setAvatarPath($this->storePublicFile($avatar, 'customers/avatars'));
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Your profile was updated.');

            return $this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER);
        }
        if ($form->isSubmitted()) {
            // Keep showing the stored values next to the form errors.
            $this->entityManager->refresh($user);
        }

        return $this->render('account/index.html.twig', [
            'form' => $form,
            'user' => $user,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /** Removes personal details, blocks the account and signs out. Order history is kept for the restaurants. */
    #[Route('/delete', name: 'app_account_delete', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, Security $security): Response
    {
        if (!$this->isCsrfTokenValid('delete-account', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $this->deletePublicFile($user->getAvatarPath());
        $user->setName('Deleted Customer')->setEmail(null)->setPhone(null)->setAvatarPath(null)
            ->setEmailVerifiedAt(null)->setPhoneVerifiedAt(null)->setStatus(User::STATUS_BANNED)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $security->logout(false);
        $this->addFlash('success', 'Your account was deleted.');

        return $this->redirectToRoute('app_home');
    }

    /** Moves an upload under public/uploads with a random name; returns the path to store, e.g. "uploads/customers/avatars/…". */
    private function storePublicFile(UploadedFile $file, string $directory): string
    {
        $filename = bin2hex(random_bytes(20)).'.'.($file->guessExtension() ?: 'bin');
        $file->move($this->publicUploadDir.'/'.$directory, $filename);

        return 'uploads/'.$directory.'/'.$filename;
    }

    private function deletePublicFile(?string $path): void
    {
        if (null !== $path && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
            @unlink($this->publicUploadDir.'/'.substr($path, strlen('uploads/')));
        }
    }
}
