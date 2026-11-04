<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Form\RestaurantProfileFormType;
use App\Repository\UserRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The restaurant's profile and the open/closed switch. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
    ) {
    }

    #[Route('/restaurant/profile', name: 'app_restaurant_profile', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user, UserRepository $users): Response
    {
        $restaurant = $this->restaurant($user);
        $form = $this->createForm(RestaurantProfileFormType::class, $restaurant);
        $details = $restaurant->getPayoutAccountDetails() ?? [];
        if (!$request->isMethod('POST')) {
            $form->get('phone')->setData($user->getPhone());
            $form->get('payoutAccountName')->setData($details['account_name'] ?? null);
            $form->get('payoutAccountNumber')->setData($details['account_number'] ?? null);
        }
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $phone = trim((string) $form->get('phone')->getData());
            if ('' !== $phone && $users->isIdentifierTakenByAnother('phone', $phone, $user)) {
                $form->get('phone')->addError(new FormError('This phone number is already registered.'));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $description = trim((string) $restaurant->getDescription());
            $restaurant->setName(trim($restaurant->getName()))
                ->setDescription('' === $description ? null : $description)
                ->setMinOrderAmount(bcround($restaurant->getMinOrderAmount(), 2))
                ->setUpdatedAt($now);
            $user->setPhone(trim((string) $form->get('phone')->getData()))->setUpdatedAt($now);

            $accountName = trim((string) $form->get('payoutAccountName')->getData());
            $accountNumber = trim((string) $form->get('payoutAccountNumber')->getData());
            $restaurant->setPayoutAccountDetails('' === $accountName && '' === $accountNumber ? null : [
                'account_name' => $accountName,
                'account_number' => $accountNumber,
            ]);

            foreach (['logo' => 'logos', 'coverPhoto' => 'covers'] as $field => $directory) {
                $file = $form->get($field)->getData();
                if (!$file instanceof UploadedFile) {
                    continue;
                }
                $path = $this->storePublicFile($file, 'restaurants/'.$directory);
                if ('logo' === $field) {
                    $this->deletePublicFile($restaurant->getLogoPath());
                    $restaurant->setLogoPath($path);
                } else {
                    $this->deletePublicFile($restaurant->getCoverPhotoPath());
                    $restaurant->setCoverPhotoPath($path);
                }
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Your restaurant profile was updated.');

            return $this->redirectToRoute('app_restaurant_profile', [], Response::HTTP_SEE_OTHER);
        }
        if ($form->isSubmitted()) {
            $this->entityManager->refresh($restaurant);
        }

        return $this->render('restaurant_profile/index.html.twig', [
            'restaurant' => $restaurant,
            'form' => $form,
            'statuses' => Restaurant::OPERATING_STATUSES,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/restaurant/status', name: 'app_restaurant_status', methods: ['POST'])]
    public function status(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('operating-status', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $status = $request->getPayload()->getString('status');
        if (!in_array($status, Restaurant::OPERATING_STATUSES, true)) {
            $this->addFlash('error', 'Choose open, closed or temporarily closed.');
        } else {
            $this->restaurant($user)->setOperatingStatus($status)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', 'You are now '.str_replace('_', ' ', $status).'.');
        }

        return $this->redirectToRoute('app_restaurant_dashboard', [], Response::HTTP_SEE_OTHER);
    }

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

    private function restaurant(User $user): Restaurant
    {
        return $user->getRestaurant() ?? throw $this->createAccessDeniedException();
    }
}
