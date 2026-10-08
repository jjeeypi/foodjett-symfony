<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PaymentTransitionService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Mutates within the caller's transaction. COD has no collected funds and becomes failed;
     * paid simulated GCash/Card payments are fully refunded.
     */
    public function cancelOrRefund(
        Payment $payment,
        PaymentActor $changedBy,
        string $note,
        \DateTimeImmutable $now,
    ): void {
        $from = $payment->getStatus();

        if (PaymentMethod::COD === $payment->getMethod()) {
            if (PaymentStatus::FAILED === $from) {
                return;
            }
            $to = PaymentStatus::FAILED;
        } else {
            if (PaymentStatus::REFUNDED === $from) {
                return;
            }
            $to = PaymentStatus::REFUNDED;
            $payment
                ->setRefundedAmount($payment->getAmount())
                ->setRefundedAt($now);
        }

        $payment
            ->setStatus($to)
            ->setUpdatedAt($now);
        $history = (new PaymentStatusHistory())
            ->setPayment($payment)
            ->setFromStatus($from)
            ->setToStatus($to)
            ->setChangedBy($changedBy)
            ->setNote($note)
            ->setCreatedAt($now);
        $payment->addStatusHistory($history);
        $this->entityManager->persist($history);
    }
}
