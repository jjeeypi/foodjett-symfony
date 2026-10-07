<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class RestaurantVoter extends Voter
{
    public const VIEW = 'RESTAURANT_VIEW';
    public const UPDATE = 'RESTAURANT_UPDATE';
    public const APPROVE = 'RESTAURANT_APPROVE';
    public const SUSPEND = 'RESTAURANT_SUSPEND';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::UPDATE, self::APPROVE, self::SUSPEND], true)
            && $subject instanceof Restaurant;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Restaurant) {
            return false;
        }

        if (UserRole::ADMIN === $user->getRole()) {
            return true;
        }

        return in_array($attribute, [self::VIEW, self::UPDATE], true) && $subject->getUser() === $user;
    }
}
