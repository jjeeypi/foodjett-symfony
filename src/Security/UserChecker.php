<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User || UserStatus::ACTIVE === $user->getStatus()) {
            return;
        }

        $message = match ($user->getStatus()) {
            UserStatus::SUSPENDED => 'Your account is suspended. Contact support for assistance.',
            UserStatus::BANNED => 'Your account is banned. Contact support for assistance.',
            default => 'Your account is not active.',
        };

        throw new CustomUserMessageAccountStatusException($message);
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
