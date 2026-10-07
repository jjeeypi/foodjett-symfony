<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Auth\CustomerRegistrationRequest;
use App\Dto\Auth\RestaurantRegistrationRequest;
use App\Dto\Auth\RiderRegistrationRequest;
use App\Entity\User;
use App\Service\RegistrationService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly ValidatorInterface $validator,
        private readonly JWTTokenManagerInterface $jwt,
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
        $data = $this->jsonBody($request);
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

        if ($response = $this->validationResponse($dto)) {
            return $response;
        }

        return $this->registeredResponse($this->registration->registerRestaurant($dto));
    }

    #[Route('/api/register/rider', name: 'api_register_rider', methods: ['POST'])]
    public function rider(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
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

        if ($response = $this->validationResponse($dto)) {
            return $response;
        }

        return $this->registeredResponse($this->registration->registerRider($dto));
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

    private function validationResponse(object $dto): ?JsonResponse
    {
        $violations = $this->validator->validate($dto);
        $errors = $this->formatViolations($violations);

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
