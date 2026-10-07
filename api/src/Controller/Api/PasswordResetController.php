<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/api/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $email = mb_strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'The submitted data is invalid.',
                'errors' => ['email' => [(string) $violations[0]->getMessage()]],
            ], 422);
        }

        $response = ['message' => 'If an account matches that email, password reset instructions have been generated.'];
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user instanceof User) {
            try {
                $token = $this->resetPasswordHelper->generateResetToken($user);
                if ($this->isDevelopmentEnvironment()) {
                    $response['reset_token'] = $token->getToken();
                    $response['expires_at'] = $token->getExpiresAt()->format(\DateTimeInterface::ATOM);
                    $response['delivery'] = 'development_log_stub';
                    $this->logger->notice('Development password reset token generated.', [
                        'user_id' => $user->getId(),
                        'reset_token' => $token->getToken(),
                    ]);
                }
            } catch (ResetPasswordExceptionInterface $exception) {
                // Keep the response generic to avoid exposing account state or throttle timing.
                $this->logger->info('Password reset request was not generated.', [
                    'user_id' => $user->getId(),
                    'reason' => $exception->getReason(),
                ]);
            }
        }

        return $this->json($response, 202);
    }

    #[Route('/api/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $token = trim(is_string($data['token'] ?? null) ? $data['token'] : '');
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $confirmation = is_string($data['password_confirmation'] ?? null) ? $data['password_confirmation'] : '';
        $errors = [];

        foreach ($this->validator->validate($token, [new Assert\NotBlank()]) as $violation) {
            $errors['token'][] = (string) $violation->getMessage();
        }
        foreach ($this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)]) as $violation) {
            $errors['password'][] = (string) $violation->getMessage();
        }
        if ($password !== $confirmation) {
            $errors['password_confirmation'][] = 'The password confirmation does not match.';
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $exception) {
            return $this->json([
                'message' => 'The password reset token is invalid or has expired.',
            ], 422);
        }

        if (!$user instanceof User) {
            return $this->json(['message' => 'The password reset token is invalid or has expired.'], 422);
        }

        $user
            ->setPassword($this->passwordHasher->hashPassword($user, $password))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->resetPasswordHelper->removeResetRequest($token);

        return $this->json(['message' => 'Your password has been reset successfully.']);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function body(Request $request): array|JsonResponse
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
    }

    private function isDevelopmentEnvironment(): bool
    {
        return in_array($this->environment, ['dev', 'test'], true);
    }
}
