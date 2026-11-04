<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureListener
{
    #[AsEventListener]
    public function onLoginFailureEvent(LoginFailureEvent $event): void
    {
        // ...
    }
}
