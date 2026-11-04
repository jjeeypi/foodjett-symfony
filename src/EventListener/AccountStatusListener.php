<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Blocks suspended and banned accounts at sign-in, with email or phone, and on remember-me logins.
 * Priority 256 matches Symfony's own user checker, so this runs before the password check.
 */
final class AccountStatusListener
{
    #[AsEventListener(priority: 256)]
    public function onCheckPassportEvent(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();
        if (!$user instanceof User || User::STATUS_ACTIVE === $user->getStatus()) {
            return;
        }

        $message = match ($user->getStatus()) {
            User::STATUS_SUSPENDED => 'Your account is suspended. Contact support for assistance.',
            User::STATUS_BANNED => 'Your account is banned. Contact support for assistance.',
            default => 'Your account is not active.',
        };

        throw new CustomUserMessageAccountStatusException($message);
    }
}
