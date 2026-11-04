<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

final class AccountStatusListener
{
    #[AsEventListener]
    public function onCheckPassportEvent(CheckPassportEvent $event): void
    {
        // ...
    }
}
