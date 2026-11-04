<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Shapes failed password logins: 403 with the reason for a blocked account, otherwise a
 * generic 401 that does not reveal whether the account exists. JWT failures are left alone.
 */
final class LoginFailureListener
{
    #[AsEventListener]
    public function onLoginFailureEvent(LoginFailureEvent $event): void
    {
        if ('login' !== $event->getFirewallName()) {
            return;
        }

        $exception = $event->getException();
        do {
            if ($exception instanceof AccountStatusException) {
                $event->setResponse(new JsonResponse(['message' => $exception->getMessageKey()], Response::HTTP_FORBIDDEN));

                return;
            }
            $exception = $exception->getPrevious();
        } while (null !== $exception);

        $event->setResponse(new JsonResponse(['message' => 'Invalid login credentials.'], Response::HTTP_UNAUTHORIZED));
    }
}
