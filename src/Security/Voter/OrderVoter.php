<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class OrderVoter extends Voter
{
    public const VIEW = 'ORDER_VIEW';
    public const UPDATE_AS_RESTAURANT = 'ORDER_UPDATE_AS_RESTAURANT';
    public const UPDATE_AS_RIDER = 'ORDER_UPDATE_AS_RIDER';
    public const CANCEL = 'ORDER_CANCEL';

    private const ATTRIBUTES = [
        self::VIEW,
        self::UPDATE_AS_RESTAURANT,
        self::UPDATE_AS_RIDER,
        self::CANCEL,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof Order;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Order) {
            return false;
        }

        // This mirrors the Laravel policy's global admin override, including operational actions.
        if (UserRole::ADMIN === $user->getRole()) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($user, $subject),
            self::UPDATE_AS_RESTAURANT => $subject->getRestaurant()->getUser() === $user,
            self::UPDATE_AS_RIDER => $this->canUpdateAsRider($user, $subject),
            self::CANCEL => $subject->getCustomer()->getUser() === $user && $subject->canBeCancelledByCustomer(),
            default => false,
        };
    }

    private function canView(User $user, Order $order): bool
    {
        if ($order->getCustomer()->getUser() === $user || $order->getRestaurant()->getUser() === $user) {
            return true;
        }

        return $this->canUpdateAsRider($user, $order);
    }

    private function canUpdateAsRider(User $user, Order $order): bool
    {
        $rider = $user->getRider();
        if (null === $rider) {
            return false;
        }

        if (null !== $order->getRider()) {
            return $order->getRider() === $rider;
        }

        return OrderStatus::FINDING_RIDER === $order->getStatus()
            && ApprovalStatus::APPROVED === $rider->getApprovalStatus();
    }
}
