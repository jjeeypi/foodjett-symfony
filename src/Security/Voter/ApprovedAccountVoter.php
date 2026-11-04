<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class ApprovedAccountVoter extends Voter
{
    public const ACCESS = 'ACCOUNT_APPROVED';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($user->getRole()) {
            User::ACCOUNT_ADMIN, User::ACCOUNT_CUSTOMER => true,
            User::ACCOUNT_RESTAURANT => Restaurant::APPROVAL_APPROVED === $user->getRestaurant()?->getApprovalStatus(),
            User::ACCOUNT_RIDER => Rider::APPROVAL_APPROVED === $user->getRider()?->getApprovalStatus(),
        };
    }
}
