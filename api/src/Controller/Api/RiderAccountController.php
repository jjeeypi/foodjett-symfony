<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\PayoutMethod;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\SensitiveDataCipher;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
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

    public function __construct(EntityManagerInterface $entityManager, private readonly UploadStorage $uploads, private readonly SensitiveDataCipher $cipher)
    {
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
        $vehicle = array_key_exists('vehicle_type', $data) ? VehicleType::tryFrom((string) $data['vehicle_type']) : $rider->getVehicleType();
        $plate = array_key_exists('plate_number', $data) ? trim((string) $data['plate_number']) : $rider->getPlateNumber();
        $payoutMethod = array_key_exists('payout_method', $data) && null !== $data['payout_method'] && '' !== $data['payout_method']
            ? PayoutMethod::tryFrom((string) $data['payout_method']) : $rider->getPayoutMethod();
        $payoutDetails = $data['payout_account_details'] ?? null;
        if (is_string($payoutDetails)) { $payoutDetails = json_decode($payoutDetails, true); }
        $errors = [];
        if ('' === $name || mb_strlen($name) > 255) { $errors['name'][] = 'Name must be between 1 and 255 characters.'; }
        if (null !== $phone && ('' === $phone || mb_strlen($phone) > 255)) { $errors['phone'][] = 'Phone must be between 1 and 255 characters.'; }
        elseif (null !== $phone && $this->phoneExists($phone, $user)) { $errors['phone'][] = 'This phone number is already in use.'; }
        if (null === $vehicle) { $errors['vehicle_type'][] = 'Vehicle type must be motorcycle, bicycle, or car.'; }
        if (VehicleType::BICYCLE !== $vehicle && (null === $plate || '' === $plate)) { $errors['plate_number'][] = 'Plate number is required unless the vehicle is a bicycle.'; }
        if (null !== $plate && mb_strlen($plate) > 255) { $errors['plate_number'][] = 'Plate number cannot exceed 255 characters.'; }
        if (array_key_exists('payout_method', $data) && null !== $data['payout_method'] && '' !== $data['payout_method'] && null === $payoutMethod) { $errors['payout_method'][] = 'Payout method must be bank or ewallet.'; }
        if (array_key_exists('payout_account_details', $data) && null !== $payoutDetails && !is_array($payoutDetails)) { $errors['payout_account_details'][] = 'Payout account details must be an object.'; }
        if ([] !== $errors) { return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422); }

        $now = new \DateTimeImmutable();
        $user->setName($name)->setPhone($phone)->setUpdatedAt($now);
        $rider->setVehicleType($vehicle)->setPlateNumber(VehicleType::BICYCLE === $vehicle && '' === (string) $plate ? null : $plate)
            ->setPayoutMethod($payoutMethod)->setUpdatedAt($now);
        if (array_key_exists('payout_account_details', $data)) {
            $rider->setEncryptedPayoutAccountDetails(null === $payoutDetails ? null : $this->cipher->encrypt($payoutDetails));
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
            ->setPhoneVerifiedAt(null)->setStatus(UserStatus::BANNED)->setUpdatedAt($now);
        $rider->setPlateNumber(null)->setPayoutMethod(null)->setEncryptedPayoutAccountDetails(null)->setUpdatedAt($now);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    private function phoneExists(string $phone, User $current): bool
    {
        return null !== $this->entityManager->getRepository(User::class)->createQueryBuilder('account')->select('account.id')
            ->andWhere('account.phone = :phone')->setParameter('phone', $phone)
            ->andWhere('account.id != :current')->setParameter('current', $current->getId())->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return array<string, mixed> */
    private function profileData(): array
    {
        $rider = $this->rider();
        $user = $this->riderUser();
        $encrypted = $rider->getEncryptedPayoutAccountDetails();
        return ['id' => $rider->getId(), 'name' => $user->getName(), 'email' => $user->getEmail(), 'phone' => $user->getPhone(),
            'avatar_path' => $user->getAvatarPath(), 'vehicle_type' => $rider->getVehicleType()->value, 'plate_number' => $rider->getPlateNumber(),
            'approval_status' => $rider->getApprovalStatus()->value, 'availability_status' => $rider->getAvailabilityStatus()->value,
            'payout_method' => $rider->getPayoutMethod()?->value,
            'payout_account_details' => null === $encrypted ? null : $this->cipher->decrypt($encrypted)];
    }
}
