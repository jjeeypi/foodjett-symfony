<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Auth\CustomerRegistrationRequest;
use App\Dto\Auth\RestaurantRegistrationRequest;
use App\Dto\Auth\RiderRegistrationRequest;
use App\Entity\User;
use App\Enum\RestaurantDocumentType;
use App\Enum\RiderDocumentType;
use App\Service\RegistrationService;
use App\Service\UploadStorage;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    private const DOCUMENT_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly RegistrationService $registration,
        private readonly ValidatorInterface $validator,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly UploadStorage $uploads,
    ) {
    }

    #[Route('/api/register', name: 'api_register_customer', methods: ['POST'])]
    public function customer(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $dto = new CustomerRegistrationRequest(
            name: $this->string($data, 'name'),
            email: $this->string($data, 'email'),
            phone: $this->string($data, 'phone'),
            password: $this->string($data, 'password'),
            passwordConfirmation: $this->string($data, 'password_confirmation'),
        );

        if ($response = $this->validationResponse($dto)) {
            return $response;
        }

        return $this->registeredResponse($this->registration->registerCustomer($dto));
    }

    #[Route('/api/register/restaurant', name: 'api_register_restaurant', methods: ['POST'])]
    public function restaurant(Request $request): JsonResponse
    {
        $data = $this->requestBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $dto = new RestaurantRegistrationRequest(
            ownerName: $this->string($data, 'owner_name'),
            email: $this->string($data, 'email'),
            phone: $this->string($data, 'phone'),
            password: $this->string($data, 'password'),
            passwordConfirmation: $this->string($data, 'password_confirmation'),
            restaurantName: $this->string($data, 'restaurant_name'),
            address: $this->string($data, 'address'),
            latitude: $this->string($data, 'latitude'),
            longitude: $this->string($data, 'longitude'),
            cuisineType: $this->string($data, 'cuisine_type'),
        );

        $documentFiles = $this->requiredDocumentFiles($request, RestaurantDocumentType::cases());
        if ($response = $this->validationResponse($dto, $documentFiles['errors'])) {
            return $response;
        }

        $documentPaths = $this->storeDocuments($documentFiles['files'], 'restaurants/documents');
        if ($documentPaths instanceof JsonResponse) {
            return $documentPaths;
        }

        try {
            $user = $this->registration->registerRestaurant($dto, $documentPaths);
        } catch (\Throwable $exception) {
            $this->deleteUploads($documentPaths);
            throw $exception;
        }

        return $this->pendingApplicationResponse($user);
    }

    #[Route('/api/register/rider', name: 'api_register_rider', methods: ['POST'])]
    public function rider(Request $request): JsonResponse
    {
        $data = $this->requestBody($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $plateNumber = $this->nullableString($data, 'plate_number');
        $dto = new RiderRegistrationRequest(
            name: $this->string($data, 'name'),
            email: $this->string($data, 'email'),
            phone: $this->string($data, 'phone'),
            password: $this->string($data, 'password'),
            passwordConfirmation: $this->string($data, 'password_confirmation'),
            vehicleType: $this->string($data, 'vehicle_type'),
            plateNumber: $plateNumber,
        );

        $documentFiles = $this->requiredDocumentFiles($request, RiderDocumentType::cases());
        if ($response = $this->validationResponse($dto, $documentFiles['errors'])) {
            return $response;
        }

        $documentPaths = $this->storeDocuments($documentFiles['files'], 'riders/documents');
        if ($documentPaths instanceof JsonResponse) {
            return $documentPaths;
        }

        try {
            $user = $this->registration->registerRider($dto, $documentPaths);
        } catch (\Throwable $exception) {
            $this->deleteUploads($documentPaths);
            throw $exception;
        }

        return $this->pendingApplicationResponse($user);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function jsonBody(Request $request): array|JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }

        return $data;
    }

    /** @return array<string, mixed>|JsonResponse */
    private function requestBody(Request $request): array|JsonResponse
    {
        if ($request->request->count() > 0) {
            return $request->request->all();
        }

        return $this->jsonBody($request);
    }

    /** @param array<string, list<string>> $additionalErrors */
    private function validationResponse(object $dto, array $additionalErrors = []): ?JsonResponse
    {
        $violations = $this->validator->validate($dto);
        $errors = array_merge($this->formatViolations($violations), $additionalErrors);

        if ($this->registration->emailExists($dto->email)) {
            $errors['email'][] = 'This email address is already registered.';
        }
        if ($this->registration->phoneExists($dto->phone)) {
            $errors['phone'][] = 'This phone number is already registered.';
        }

        return [] === $errors ? null : $this->json([
            'message' => 'The submitted data is invalid.',
            'errors' => $errors,
        ], 422);
    }

    /** @return array<string, list<string>> */
    private function formatViolations(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = $this->snakeCase($violation->getPropertyPath());
            $errors[$field][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private function registeredResponse(User $user): JsonResponse
    {
        $approvalStatus = $user->getRestaurant()?->getApprovalStatus()->value
            ?? $user->getRider()?->getApprovalStatus()->value;

        return $this->json([
            'token' => $this->jwt->create($user),
            'user' => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'role' => $user->getRole()->value,
                'status' => $user->getStatus()->value,
                'approval_status' => $approvalStatus,
            ],
        ], 201);
    }

    private function pendingApplicationResponse(User $user): JsonResponse
    {
        $approvalStatus = $user->getRestaurant()?->getApprovalStatus()->value
            ?? $user->getRider()?->getApprovalStatus()->value;

        return $this->json([
            'message' => 'Your application was submitted and is under review.',
            'user' => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'role' => $user->getRole()->value,
                'status' => $user->getStatus()->value,
                'approval_status' => $approvalStatus,
            ],
        ], 201);
    }

    /**
     * @template T of \BackedEnum
     * @param list<T> $types
     * @return array{files: array<string, UploadedFile>, errors: array<string, list<string>>}
     */
    private function requiredDocumentFiles(Request $request, array $types): array
    {
        $files = [];
        $errors = [];
        foreach ($types as $type) {
            $file = $request->files->get($type->value);
            if (!$file instanceof UploadedFile) {
                $errors[$type->value][] = 'This document is required.';
                continue;
            }
            $files[$type->value] = $file;
        }

        return ['files' => $files, 'errors' => $errors];
    }

    /**
     * @param array<string, UploadedFile> $files
     *
     * @return array<string, string>|JsonResponse
     */
    private function storeDocuments(array $files, string $directory): array|JsonResponse
    {
        $paths = [];
        foreach ($files as $type => $file) {
            try {
                $paths[$type] = $this->uploads->store($file, $directory, self::DOCUMENT_MIME_TYPES);
            } catch (\InvalidArgumentException $exception) {
                $this->deleteUploads($paths);

                return $this->json([
                    'message' => 'The submitted data is invalid.',
                    'errors' => [$type => [$exception->getMessage()]],
                ], 422);
            }
        }

        return $paths;
    }

    /** @param array<string, string> $paths */
    private function deleteUploads(array $paths): void
    {
        foreach ($paths as $path) {
            $this->uploads->delete($path);
        }
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) || is_numeric($data[$key] ?? null)
            ? trim((string) $data[$key])
            : '';
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $this->string($data, $key);

        return '' === $value ? null : $value;
    }

    private function snakeCase(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
