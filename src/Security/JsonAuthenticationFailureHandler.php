<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final class JsonAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $statusException = $this->findAccountStatusException($exception);

        if (null !== $statusException) {
            return new JsonResponse([
                'message' => $statusException->getMessageKey(),
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'message' => 'Invalid login credentials.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function findAccountStatusException(\Throwable $exception): ?AccountStatusException
    {
        do {
            if ($exception instanceof AccountStatusException) {
                return $exception;
            }

            $exception = $exception->getPrevious();
        } while (null !== $exception);

        return null;
    }
}
