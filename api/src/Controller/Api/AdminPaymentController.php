<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\User;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
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
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('payment')
            ->innerJoin('payment.order', 'orders')->addSelect('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->innerJoin('orders.customer', 'customer')->addSelect('customer')
            ->innerJoin('customer.user', 'customerUser')->addSelect('customerUser')
            ->orderBy('payment.createdAt', 'DESC');
        if ('' !== ($methodValue = trim((string) $request->query->get('method', '')))) {
            $method = PaymentMethod::tryFrom($methodValue);
            if (null === $method) {
                return $this->json(['message' => 'Invalid method.'], 422);
            }
            $query->andWhere('payment.method = :method')->setParameter('method', $method->value);
        }
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = PaymentStatus::tryFrom($statusValue);
            if (null === $status) {
                return $this->json(['message' => 'Invalid status.'], 422);
            }
            $query->andWhere('payment.status = :status')->setParameter('status', $status->value);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $parameter => $operator) {
            if ('' === ($value = trim((string) $request->query->get($parameter, '')))) {
                continue;
            }
            try {
                $date = new \DateTimeImmutable($value.('date_to' === $parameter && 10 === strlen($value) ? ' 23:59:59' : ''));
            } catch (\Throwable) {
                return $this->json(['message' => $parameter.' must be a valid date.'], 422);
            }
            $query->andWhere('payment.paidAt '.$operator.' :'.$parameter)->setParameter($parameter, $date);
        }

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
        if (array_key_exists('amount', $data) && (!is_numeric($data['amount']) || (float) $data['amount'] <= 0)) {
            return $this->json(['message' => 'amount must be greater than zero.'], 422);
        }
        $admin = $this->admin();

        try {
            /** @var Payment $updated */
            $updated = $this->entityManager->wrapInTransaction(function () use ($payment, $data, $reason, $admin): Payment {
                $locked = $this->entityManager->find(Payment::class, $payment->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof Payment) {
                    throw new \RuntimeException('Payment not found.');
                }
                if (!in_array($locked->getStatus(), [PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED], true)) {
                    throw new \DomainException('Only paid or partially refunded payments can be refunded.');
                }
                $remaining = round((float) $locked->getAmount() - (float) $locked->getRefundedAmount(), 2);
                $amount = array_key_exists('amount', $data) ? round((float) $data['amount'], 2) : $remaining;
                if ($amount <= 0 || $amount > $remaining) {
                    throw new \DomainException('The refund amount cannot exceed the remaining refundable balance.');
                }
                $from = $locked->getStatus();
                $newRefunded = round((float) $locked->getRefundedAmount() + $amount, 2);
                $to = $newRefunded >= (float) $locked->getAmount() ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;
                $now = new \DateTimeImmutable();
                $note = sprintf('Admin recorded a %s refund of PHP %s%s', PaymentStatus::REFUNDED === $to ? 'full' : 'partial', number_format($amount, 2), '' === $reason ? '.' : ': '.$reason);
                $locked->setRefundedAmount(number_format($newRefunded, 2, '.', ''))->setRefundedAt($now)->setStatus($to)->setUpdatedAt($now);
                $history = (new PaymentStatusHistory())->setPayment($locked)->setFromStatus($from)->setToStatus($to)
                    ->setChangedBy(PaymentActor::ADMIN)->setNote($note)->setCreatedAt($now);
                $locked->addStatusHistory($history);
                $this->entityManager->persist($history);
                $this->audit->record($admin, 'payment.refunded', 'payment', (string) $locked->getId(), [
                    'status' => ['from' => $from->value, 'to' => $to->value],
                    'refund_amount' => number_format($amount, 2, '.', ''),
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
            'paid_at' => $payment->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'refunded_at' => $payment->getRefundedAt()?->format(\DateTimeInterface::ATOM),
        ];
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
