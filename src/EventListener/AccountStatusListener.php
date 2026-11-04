<?php

namespace App\EventListener;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Blocks suspended and banned accounts on every authentication (password login and JWT).
 * Priority 256 matches Symfony's own user checker, so this runs before the password check.
 */
final class AccountStatusListener
{
    #[AsEventListener(priority: 256)]
    public function onCheckPassportEvent(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();
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
}
