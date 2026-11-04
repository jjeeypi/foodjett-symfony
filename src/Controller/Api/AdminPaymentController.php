<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Repository\AuditLogRepository;
use App\Repository\PaymentRepository;
use App\Service\ApiPaginator;
use App\Service\PaymentTransitionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/payments', name: 'api_admin_payments_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogRepository $audit,
        private readonly PaymentRepository $payments,
        private readonly PaymentTransitionService $paymentTransitions,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $method = null;
        if ('' !== ($methodValue = trim((string) $request->query->get('method', '')))) {
            $method = PaymentMethod::tryFrom($methodValue);
            if (null === $method) {
                return $this->json(['message' => 'Invalid method.'], 422);
            }
        }
        $status = null;
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = PaymentStatus::tryFrom($statusValue);
            if (null === $status) {
                return $this->json(['message' => 'Invalid status.'], 422);
            }
        }
        $dates = ['date_from' => null, 'date_to' => null];
        foreach (array_keys($dates) as $parameter) {
            if ('' === ($value = trim((string) $request->query->get($parameter, '')))) {
                continue;
            }
            try {
                $dates[$parameter] = new \DateTimeImmutable($value.('date_to' === $parameter && 10 === strlen($value) ? ' 23:59:59' : ''));
            } catch (\Throwable) {
                return $this->json(['message' => $parameter.' must be a valid date.'], 422);
            }
        }
        $query = $this->payments->createAdminListQuery($method, $status, $dates['date_from'], $dates['date_to']);

        return $this->json($this->paginator->paginate($query, $request, fn (Payment $payment): array => $this->normalize($payment)));
    }

    #[Route('/{id}/refund', name: 'refund', methods: ['POST'])]
    public function refund(Payment $payment, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');
        if (mb_strlen($reason) > 1000) {
            return $this->json(['message' => 'reason cannot exceed 1000 characters.'], 422);
        }
        $requested = array_key_exists('amount', $data) ? $this->plainAmount($data['amount']) : null;
        if (array_key_exists('amount', $data) && (null === $requested || 1 !== bccomp($requested, '0', 2))) {
            return $this->json(['message' => 'amount must be greater than zero.'], 422);
        }
        $admin = $this->admin();

        try {
            /** @var Payment $updated */
            $updated = $this->entityManager->wrapInTransaction(function () use ($payment, $requested, $reason, $admin): Payment {
                $locked = $this->entityManager->find(Payment::class, $payment->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof Payment) {
                    throw new \RuntimeException('Payment not found.');
                }
                $from = $locked->getStatus();
                $amount = $requested ?? bcsub($locked->getAmount(), $locked->getRefundedAmount(), 2);
                $to = $this->paymentTransitions->refund($locked, $amount, PaymentActor::ADMIN, '' === $reason ? null : $reason, new \DateTimeImmutable());
                $this->audit->record($admin, 'payment.refunded', 'payment', (string) $locked->getId(), [
                    'status' => ['from' => $from->value, 'to' => $to->value],
                    'refund_amount' => $amount,
                    'refunded_amount' => $locked->getRefundedAmount(),
                    'reason' => '' === $reason ? null : $reason,
                ]);
                $this->entityManager->flush();

                return $locked;
            });
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json(['payment' => $this->normalize($updated)]);
    }

    /** @return array<string, mixed> */
    private function normalize(Payment $payment): array
    {
        return [
            'id' => $payment->getId(),
            'order' => [
                'id' => $payment->getOrder()->getId(),
                'order_number' => $payment->getOrder()->getOrderNumber(),
                'restaurant_name' => $payment->getOrder()->getRestaurant()->getName(),
                'customer_name' => $payment->getOrder()->getCustomer()->getUser()->getName(),
            ],
            'method' => $payment->getMethod()->value,
            'status' => $payment->getStatus()->value,
            'amount' => $payment->getAmount(),
            'refunded_amount' => $payment->getRefundedAmount(),
            'transaction_reference' => $payment->getTransactionReference(),
            'created_at' => $payment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'paid_at' => $payment->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'refunded_at' => $payment->getRefundedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Accepts a plain positive or zero decimal (no exponent, sign or spaces) and returns it
     * rounded half-up to two places, or null when the value is not such a number.
     */
    private function plainAmount(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || 1 !== preg_match('/^\d{1,8}(\.\d+)?$/', trim($value))) {
            return null;
        }

        return bcround(trim($value), 2);
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
