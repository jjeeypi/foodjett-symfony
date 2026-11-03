<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RestaurantRegistrationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $ownerName,
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
        #[Assert\Length(max: 255)]
        public string $restaurantName,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $address,
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^-?(?:90(?:\.0+)?|[0-8]?\d(?:\.\d+)?)$/', message: 'Latitude must be between -90 and 90.')]
        public string $latitude,
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^-?(?:180(?:\.0+)?|(?:1[0-7]\d|[0-9]?\d)(?:\.\d+)?)$/', message: 'Longitude must be between -180 and 180.')]
        public string $longitude,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $cuisineType,
    ) {
    }
}
