<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Rider;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class RiderVoter extends Voter
{
    public const VIEW = 'RIDER_VIEW';
    public const UPDATE = 'RIDER_UPDATE';
    public const APPROVE = 'RIDER_APPROVE';
    public const SUSPEND = 'RIDER_SUSPEND';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::UPDATE, self::APPROVE, self::SUSPEND], true)
            && $subject instanceof Rider;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Rider) {
            return false;
        }

        if (UserRole::ADMIN === $user->getRole()) {
            return true;
        }

        return in_array($attribute, [self::VIEW, self::UPDATE], true) && $subject->getUser() === $user;
    }
}
