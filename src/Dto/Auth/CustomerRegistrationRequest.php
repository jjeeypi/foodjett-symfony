<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CustomerRegistrationRequest
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
    ) {
    }
}
