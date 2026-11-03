<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\UserRole;
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
            UserRole::ADMIN, UserRole::CUSTOMER => true,
            UserRole::RESTAURANT => ApprovalStatus::APPROVED === $user->getRestaurant()?->getApprovalStatus(),
            UserRole::RIDER => ApprovalStatus::APPROVED === $user->getRider()?->getApprovalStatus(),
        };
    }
}
