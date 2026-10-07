<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class ConversationVoter extends Voter
{
    public const VIEW = 'CONVERSATION_VIEW';
    public const SEND = 'CONVERSATION_SEND';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::SEND], true) && $subject instanceof Conversation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Conversation) {
            return false;
        }

        // Admins can inspect chat for disputes, but deliberately cannot impersonate a participant.
        if (UserRole::ADMIN === $user->getRole()) {
            return self::VIEW === $attribute;
        }

        if (self::SEND === $attribute && null !== $subject->getClosedAt()) {
            return false;
        }

        $order = $subject->getOrder();
        if ($order->getCustomer()->getUser() === $user) {
            return true;
        }

        return match ($subject->getType()) {
            ConversationType::CUSTOMER_RESTAURANT => $order->getRestaurant()->getUser() === $user,
            ConversationType::CUSTOMER_RIDER => null !== $order->getRider() && $order->getRider()?->getUser() === $user,
        };
    }
}
