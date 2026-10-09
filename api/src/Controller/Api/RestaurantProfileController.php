<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Restaurant;
use App\Entity\RestaurantOperatingHour;
use App\Enum\PayoutMethod;
use App\Enum\RestaurantOperatingStatus;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant', name: 'api_restaurant_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantProfileController extends AbstractRestaurantController
{
    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UploadStorage $uploads,
    ) {
    }

    #[Route('/profile', name: 'profile_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(['restaurant' => $this->serializeRestaurant($this->restaurant())]);
    }

    #[Route('/profile', name: 'profile_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $errors = [];
        foreach (['name', 'address'] as $required) {
            if (array_key_exists($required, $data) && '' === trim((string) $data[$required])) {
                $errors[$required][] = 'This value cannot be blank.';
            }
        }
        foreach (['latitude', 'longitude', 'min_order_amount'] as $numeric) {
            if (array_key_exists($numeric, $data) && !is_numeric($data[$numeric])) {
                $errors[$numeric][] = 'This value must be numeric.';
            }
        }
        if (isset($data['latitude']) && ((float) $data['latitude'] < -90 || (float) $data['latitude'] > 90)) {
            $errors['latitude'][] = 'Latitude must be between -90 and 90.';
        }
        if (isset($data['longitude']) && ((float) $data['longitude'] < -180 || (float) $data['longitude'] > 180)) {
            $errors['longitude'][] = 'Longitude must be between -180 and 180.';
        }
        if (isset($data['default_prep_time_minutes']) && (!is_numeric($data['default_prep_time_minutes']) || (int) $data['default_prep_time_minutes'] < 1 || (int) $data['default_prep_time_minutes'] > 240)) {
            $errors['default_prep_time_minutes'][] = 'Preparation time must be between 1 and 240 minutes.';
        }
        if (isset($data['min_order_amount']) && is_numeric($data['min_order_amount']) && (float) $data['min_order_amount'] < 0) {
            $errors['min_order_amount'][] = 'Minimum order amount cannot be negative.';
        }

        $payoutMethod = null;
        if (array_key_exists('payout_method', $data) && null !== $data['payout_method'] && '' !== $data['payout_method']) {
            $payoutMethod = PayoutMethod::tryFrom((string) $data['payout_method']);
            if (null === $payoutMethod) {
                $errors['payout_method'][] = 'Payout method must be bank or ewallet.';
            }
        }
        $payoutDetails = $data['payout_account_details'] ?? null;
        if (is_string($payoutDetails)) {
            $payoutDetails = json_decode($payoutDetails, true);
        }
        if (array_key_exists('payout_account_details', $data) && null !== $payoutDetails && !is_array($payoutDetails)) {
            $errors['payout_account_details'][] = 'Payout account details must be an object.';
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }

        if (array_key_exists('name', $data)) {
            $restaurant->setName(trim((string) $data['name']));
        }
        if (array_key_exists('description', $data)) {
            $restaurant->setDescription($this->nullableString($data['description']));
        }
        if (array_key_exists('cuisine_type', $data)) {
            $restaurant->setCuisineType($this->nullableString($data['cuisine_type']));
        }
        if (array_key_exists('address', $data)) {
            $restaurant->setAddress(trim((string) $data['address']));
        }
        if (array_key_exists('latitude', $data)) {
            $restaurant->setLatitude((string) $data['latitude']);
        }
        if (array_key_exists('longitude', $data)) {
            $restaurant->setLongitude((string) $data['longitude']);
        }
        if (array_key_exists('default_prep_time_minutes', $data)) {
            $restaurant->setDefaultPrepTimeMinutes((int) $data['default_prep_time_minutes']);
        }
        if (array_key_exists('min_order_amount', $data)) {
            $restaurant->setMinOrderAmount(number_format((float) $data['min_order_amount'], 2, '.', ''));
        }
        if (array_key_exists('payout_method', $data)) {
            $restaurant->setPayoutMethod($payoutMethod);
        }
        if (array_key_exists('payout_account_details', $data)) {
            $restaurant->setPayoutAccountDetails($payoutDetails);
        }

        foreach (['logo' => 'logo', 'cover_photo' => 'covers'] as $field => $directory) {
            $file = $request->files->get($field);
            if (null === $file) {
                continue;
            }
            try {
                $path = $this->uploads->store($file, 'restaurants/'.$directory, self::IMAGE_MIME_TYPES);
            } catch (\InvalidArgumentException $exception) {
                return $this->json(['message' => $exception->getMessage(), 'errors' => [$field => [$exception->getMessage()]]], 422);
            }
            if ('logo' === $field) {
                $this->uploads->delete($restaurant->getLogoPath());
                $restaurant->setLogoPath($path);
            } else {
                $this->uploads->delete($restaurant->getCoverPhotoPath());
                $restaurant->setCoverPhotoPath($path);
            }
        }

        $restaurant->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Restaurant profile updated.', 'restaurant' => $this->serializeRestaurant($restaurant)]);
    }

    #[Route('/operating-status', name: 'operating_status', methods: ['PATCH'])]
    public function operatingStatus(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $status = RestaurantOperatingStatus::tryFrom((string) ($data['operating_status'] ?? ''));
        if (null === $status) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => ['operating_status' => ['Status must be open, closed, or temporarily_closed.']]], 422);
        }
        $restaurant = $this->restaurant();
        $restaurant->setOperatingStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Operating status updated.', 'operating_status' => $status->value]);
    }

    #[Route('/operating-hours', name: 'operating_hours_show', methods: ['GET'])]
    public function operatingHours(): JsonResponse
    {
        return $this->json(['hours' => array_map($this->serializeHour(...), $this->restaurant()->getOperatingHours()->toArray())]);
    }

    #[Route('/operating-hours', name: 'operating_hours_update', methods: ['PUT'])]
    public function updateOperatingHours(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $rows = $data['hours'] ?? $data;
        if (!is_array($rows)) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => ['hours' => ['Hours must be an array.']]], 422);
        }

        $parsed = [];
        $errors = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $errors["hours.$index"][] = 'Each entry must be an object.';
                continue;
            }
            $day = filter_var($row['day_of_week'] ?? null, FILTER_VALIDATE_INT);
            $opens = $this->parseTime($row['opens_at'] ?? null);
            $closes = $this->parseTime($row['closes_at'] ?? null);
            if (false === $day || $day < 0 || $day > 6) {
                $errors["hours.$index.day_of_week"][] = 'Day must be between 0 and 6.';
            } elseif (isset($parsed[$day])) {
                $errors["hours.$index.day_of_week"][] = 'Each day may appear only once.';
            }
            if (null === $opens) {
                $errors["hours.$index.opens_at"][] = 'Opening time must use HH:MM format.';
            }
            if (null === $closes) {
                $errors["hours.$index.closes_at"][] = 'Closing time must use HH:MM format.';
            }
            if (false !== $day && $day >= 0 && $day <= 6 && null !== $opens && null !== $closes) {
                $parsed[$day] = [$opens, $closes];
            }
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }

        $restaurant = $this->restaurant();
        $existing = [];
        foreach ($restaurant->getOperatingHours() as $hour) {
            $existing[$hour->getDayOfWeek()] = $hour;
        }
        $now = new \DateTimeImmutable();
        foreach ($parsed as $day => [$opens, $closes]) {
            $hour = $existing[$day] ?? (new RestaurantOperatingHour())->setRestaurant($restaurant)->setDayOfWeek($day)->setCreatedAt($now);
            $hour->setOpensAt($opens)->setClosesAt($closes)->setUpdatedAt($now);
            $this->entityManager->persist($hour);
            unset($existing[$day]);
        }
        foreach ($existing as $hour) {
            $this->entityManager->remove($hour);
        }
        $this->entityManager->flush();

        $hours = $this->entityManager->getRepository(RestaurantOperatingHour::class)->findBy(['restaurant' => $restaurant], ['dayOfWeek' => 'ASC']);

        return $this->json(['message' => 'Operating hours updated.', 'hours' => array_map($this->serializeHour(...), $hours)]);
    }

    /** @return array<string, mixed> */
    private function serializeRestaurant(Restaurant $restaurant): array
    {
        return [
            'id' => $restaurant->getId(), 'name' => $restaurant->getName(), 'slug' => $restaurant->getSlug(),
            'description' => $restaurant->getDescription(), 'logo_path' => $restaurant->getLogoPath(),
            'cover_photo_path' => $restaurant->getCoverPhotoPath(), 'cuisine_type' => $restaurant->getCuisineType(),
            'address' => $restaurant->getAddress(), 'latitude' => $restaurant->getLatitude(), 'longitude' => $restaurant->getLongitude(),
            'default_prep_time_minutes' => $restaurant->getDefaultPrepTimeMinutes(), 'min_order_amount' => $restaurant->getMinOrderAmount(),
            'commission_rate' => $restaurant->getCommissionRate(), 'approval_status' => $restaurant->getApprovalStatus()->value,
            'operating_status' => $restaurant->getOperatingStatus()->value, 'payout_method' => $restaurant->getPayoutMethod()?->value,
            'payout_account_details' => $restaurant->getPayoutAccountDetails(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeHour(RestaurantOperatingHour $hour): array
    {
        return ['id' => $hour->getId(), 'day_of_week' => $hour->getDayOfWeek(), 'opens_at' => $hour->getOpensAt()->format('H:i'), 'closes_at' => $hour->getClosesAt()->format('H:i')];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!H:i', $value) ?: null;
    }
}
