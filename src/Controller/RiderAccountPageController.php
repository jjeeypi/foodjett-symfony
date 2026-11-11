<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\User;
use App\Form\RiderProfileFormType;
use App\Repository\RiderEarningRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rider's profile: name, phone, photo, vehicle and payout account. The payout account
 * is encrypted with AES-256-GCM using a key derived from the app secret.
 */
#[IsGranted('ROLE_RIDER')]
#[Route('/rider/account')]
final class RiderAccountPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
        #[Autowire('%app.private_upload_dir%')]
        private readonly string $privateUploadDir,
    ) {
    }

    #[Route('', name: 'app_rider_account', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user, UserRepository $users, RiderEarningRepository $earnings): Response
    {
        $rider = $this->rider($user);
        $form = $this->createForm(RiderProfileFormType::class, $rider);
        if (!$request->isMethod('POST')) {
            $details = null === $rider->getEncryptedPayoutAccountDetails() ? [] : $this->decryptPayoutDetails($rider->getEncryptedPayoutAccountDetails());
            $form->get('name')->setData($user->getName());
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
            $user->setName(trim((string) $form->get('name')->getData()))->setPhone(trim((string) $form->get('phone')->getData()))->setUpdatedAt($now);
            $plate = trim((string) $rider->getPlateNumber());
            $rider->setPlateNumber('' === $plate ? null : $plate)->setUpdatedAt($now);

            $accountName = trim((string) $form->get('payoutAccountName')->getData());
            $accountNumber = trim((string) $form->get('payoutAccountNumber')->getData());
            $rider->setEncryptedPayoutAccountDetails('' === $accountName && '' === $accountNumber ? null
                : $this->encryptPayoutDetails(['account_name' => $accountName, 'account_number' => $accountNumber]));

            $avatar = $form->get('avatar')->getData();
            if ($avatar instanceof UploadedFile) {
                $this->deletePublicFile($user->getAvatarPath());
                $filename = bin2hex(random_bytes(20)).'.'.($avatar->guessExtension() ?: 'bin');
                $avatar->move($this->publicUploadDir.'/riders/avatars', $filename);
                $user->setAvatarPath('uploads/riders/avatars/'.$filename);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Your profile was updated.');

            return $this->redirectToRoute('app_rider_account', [], Response::HTTP_SEE_OTHER);
        }
        if ($form->isSubmitted()) {
            $this->entityManager->refresh($rider);
        }

        $today = (new \DateTimeImmutable())->setTime(0, 0);

        return $this->render('rider_account_page/index.html.twig', [
            'form' => $form,
            'rider' => $rider,
            'earnings' => Rider::APPROVAL_APPROVED === $rider->getApprovalStatus() ? [
                'Today' => bcround($earnings->sumEarnedByRiderSince($rider, $today), 2),
                'This week' => bcround($earnings->sumEarnedByRiderSince($rider, $today->modify('monday this week')), 2),
                'This month' => bcround($earnings->sumEarnedByRiderSince($rider, $today->modify('first day of this month')), 2),
            ] : null,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    /** Removes personal details and documents, blocks the account and signs out. Delivery history is kept. */
    #[Route('/delete', name: 'app_rider_account_delete', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, Security $security): Response
    {
        if (!$this->isCsrfTokenValid('delete-rider-account', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $rider = $this->rider($user);
        $this->deletePublicFile($user->getAvatarPath());
        foreach ($rider->getDocuments()->toArray() as $document) {
            $path = $document->getFilePath();
            if (!str_contains($path, '..')) {
                @unlink(str_starts_with($path, 'uploads/') ? $this->publicUploadDir.'/'.substr($path, 8) : $this->privateUploadDir.'/'.$path);
            }
            $rider->removeDocument($document);
            $this->entityManager->remove($document);
        }
        $now = new \DateTimeImmutable();
        $user->setName('Deleted Rider')->setEmail(null)->setPhone(null)->setAvatarPath(null)->setEmailVerifiedAt(null)
            ->setPhoneVerifiedAt(null)->setStatus(User::STATUS_BANNED)->setUpdatedAt($now);
        $rider->setPlateNumber(null)->setPayoutMethod(null)->setEncryptedPayoutAccountDetails(null)
            ->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)->setUpdatedAt($now);
        $this->entityManager->flush();
        $security->logout(false);
        $this->addFlash('success', 'Your rider account was deleted.');

        return $this->redirectToRoute('app_home');
    }

    /** @param array<string, mixed> $details */
    private function encryptPayoutDetails(array $details): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($details, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->payoutKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $ciphertext) {
            throw new \RuntimeException('Sensitive data could not be encrypted.');
        }

        return base64_encode("FJ1\0".$iv.$tag.$ciphertext);
    }

    /** @return array<string, mixed> */
    private function decryptPayoutDetails(string $value): array
    {
        $payload = base64_decode($value, true);
        if (false === $payload || !str_starts_with($payload, "FJ1\0") || strlen($payload) < 32) {
            throw new \RuntimeException('Encrypted payout details are invalid.');
        }
        $plaintext = openssl_decrypt(substr($payload, 32), 'aes-256-gcm', $this->payoutKey(), OPENSSL_RAW_DATA, substr($payload, 4, 12), substr($payload, 16, 16));
        if (false === $plaintext) {
            throw new \RuntimeException('Encrypted payout details could not be decrypted.');
        }
        $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new \RuntimeException('Encrypted payout details are invalid.');
    }

    private function payoutKey(): string
    {
        return hash('sha256', $this->secret, true);
    }

    private function deletePublicFile(?string $path): void
    {
        if (null !== $path && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
            @unlink($this->publicUploadDir.'/'.substr($path, strlen('uploads/')));
        }
    }

    private function rider(User $user): Rider
    {
        return $user->getRider() ?? throw $this->createAccessDeniedException();
    }
}
