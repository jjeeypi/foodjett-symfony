<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider', name: 'api_rider_account_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderAccountController extends AbstractRiderController
{
    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly UploadStorage $uploads,
        private readonly UserRepository $users,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
        parent::__construct($entityManager);
    }

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        return $this->json(['profile' => $this->profileData()]);
    }

    #[Route('/profile', name: 'profile_update', methods: ['PATCH'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $rider = $this->rider();
        $user = $this->riderUser();
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $user->getName();
        $phone = array_key_exists('phone', $data) ? trim((string) $data['phone']) : $user->getPhone();
        $vehicle = array_key_exists('vehicle_type', $data) ? (in_array((string) $data['vehicle_type'], Rider::VEHICLE_TYPES, true) ? (string) $data['vehicle_type'] : null) : $rider->getVehicleType();
        $plate = array_key_exists('plate_number', $data) ? trim((string) $data['plate_number']) : $rider->getPlateNumber();
        $payoutMethod = array_key_exists('payout_method', $data) && null !== $data['payout_method'] && '' !== $data['payout_method']
            ? (in_array((string) $data['payout_method'], Restaurant::PAYOUT_METHODS, true) ? (string) $data['payout_method'] : null) : $rider->getPayoutMethod();
        $payoutDetails = $data['payout_account_details'] ?? null;
        if (is_string($payoutDetails)) { $payoutDetails = json_decode($payoutDetails, true); }
        $errors = [];
        if ('' === $name || mb_strlen($name) > 255) { $errors['name'][] = 'Name must be between 1 and 255 characters.'; }
        if (null !== $phone && ('' === $phone || mb_strlen($phone) > 255)) { $errors['phone'][] = 'Phone must be between 1 and 255 characters.'; }
        elseif (null !== $phone && $this->phoneExists($phone, $user)) { $errors['phone'][] = 'This phone number is already in use.'; }
        if (null === $vehicle) { $errors['vehicle_type'][] = 'Vehicle type must be motorcycle, bicycle, or car.'; }
        if (Rider::VEHICLE_BICYCLE !== $vehicle && (null === $plate || '' === $plate)) { $errors['plate_number'][] = 'Plate number is required unless the vehicle is a bicycle.'; }
        if (null !== $plate && mb_strlen($plate) > 255) { $errors['plate_number'][] = 'Plate number cannot exceed 255 characters.'; }
        if (array_key_exists('payout_method', $data) && null !== $data['payout_method'] && '' !== $data['payout_method'] && null === $payoutMethod) { $errors['payout_method'][] = 'Payout method must be bank or ewallet.'; }
        if (array_key_exists('payout_account_details', $data) && null !== $payoutDetails && !is_array($payoutDetails)) { $errors['payout_account_details'][] = 'Payout account details must be an object.'; }
        if ([] !== $errors) { return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422); }

        $now = new \DateTimeImmutable();
        $user->setName($name)->setPhone($phone)->setUpdatedAt($now);
        $rider->setVehicleType($vehicle)->setPlateNumber(Rider::VEHICLE_BICYCLE === $vehicle && '' === (string) $plate ? null : $plate)
            ->setPayoutMethod($payoutMethod)->setUpdatedAt($now);
        if (array_key_exists('payout_account_details', $data)) {
            $rider->setEncryptedPayoutAccountDetails(null === $payoutDetails ? null : $this->encryptPayoutDetails($payoutDetails));
        }
        $avatar = $request->files->get('avatar');
        if (null !== $avatar) {
            try { $path = $this->uploads->store($avatar, 'riders/avatars', self::IMAGE_MIME_TYPES); }
            catch (\InvalidArgumentException $exception) { return $this->json(['message' => $exception->getMessage(), 'errors' => ['avatar' => [$exception->getMessage()]]], 422); }
            $this->uploads->delete($user->getAvatarPath());
            $user->setAvatarPath($path);
        }
        $this->entityManager->flush();

        return $this->json(['message' => 'Rider profile updated.', 'profile' => $this->profileData()]);
    }

    #[Route('/account', name: 'delete', methods: ['DELETE'])]
    public function deleteAccount(): JsonResponse
    {
        $rider = $this->rider();
        $user = $this->riderUser();
        $this->uploads->delete($user->getAvatarPath());
        foreach ($rider->getDocuments() as $document) {
            $this->uploads->delete($document->getFilePath());
            $this->entityManager->remove($document);
        }
        $now = new \DateTimeImmutable();
        $user->setName('Deleted Rider')->setEmail(null)->setPhone(null)->setAvatarPath(null)->setEmailVerifiedAt(null)
            ->setPhoneVerifiedAt(null)->setStatus(User::STATUS_BANNED)->setUpdatedAt($now);
        $rider->setPlateNumber(null)->setPayoutMethod(null)->setEncryptedPayoutAccountDetails(null)->setUpdatedAt($now);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    private function phoneExists(string $phone, User $current): bool
    {
        return $this->users->isIdentifierTakenByAnother('phone', $phone, $current);
    }

    /** @return array<string, mixed> */
    private function profileData(): array
    {
        $rider = $this->rider();
        $user = $this->riderUser();
        $encrypted = $rider->getEncryptedPayoutAccountDetails();
        return ['id' => $rider->getId(), 'name' => $user->getName(), 'email' => $user->getEmail(), 'phone' => $user->getPhone(),
            'avatar_path' => $user->getAvatarPath(), 'vehicle_type' => $rider->getVehicleType(), 'plate_number' => $rider->getPlateNumber(),
            'approval_status' => $rider->getApprovalStatus(), 'availability_status' => $rider->getAvailabilityStatus(),
            'current_latitude' => $rider->getCurrentLatitude(), 'current_longitude' => $rider->getCurrentLongitude(),
            'last_location_at' => $rider->getLastLocationAt()?->format(\DateTimeInterface::ATOM),
            'payout_method' => $rider->getPayoutMethod(),
            'payout_account_details' => null === $encrypted ? null : $this->decryptPayoutDetails($encrypted)];
    }

    /**
     * Payout account details are stored encrypted with AES-256-GCM, keyed from the app secret.
     *
     * @param array<string, mixed> $details
     */
    private function encryptPayoutDetails(array $details): string
    {
        $plaintext = json_encode($details, JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->payoutKey(), OPENSSL_RAW_DATA, $iv, $tag);
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
        $iv = substr($payload, 4, 12);
        $tag = substr($payload, 16, 16);
        $ciphertext = substr($payload, 32);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->payoutKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $plaintext) {
            throw new \RuntimeException('Encrypted payout details could not be decrypted.');
        }
        $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Encrypted payout details are invalid.');
        }

        return $decoded;
    }

    private function payoutKey(): string
    {
        return hash('sha256', $this->secret, true);
    }
}
