<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use App\Enum\VehicleType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class RiderRegistrationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 255)]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^\+?[0-9]{7,20}$/', message: 'Enter a valid phone number.')]
        public string $phone,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $password,
        #[Assert\EqualTo(propertyPath: 'password', message: 'The password confirmation does not match.')]
        public string $passwordConfirmation,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['motorcycle', 'bicycle', 'car'])]
        public string $vehicleType,
        #[Assert\Length(max: 255)]
        public ?string $plateNumber,
    ) {
    }

    #[Assert\Callback]
    public function validatePlateNumber(ExecutionContextInterface $context): void
    {
        if (VehicleType::BICYCLE->value !== $this->vehicleType && null === $this->plateNumber) {
            $context->buildViolation('A plate number is required for this vehicle type.')
                ->atPath('plateNumber')
                ->addViolation();
        }
    }
}
