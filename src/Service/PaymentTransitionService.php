<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of Payment.status. Every status it sets is recorded as authorized so that
 * StatusGuardListener can reject any other change to the column at flush time. All methods
 * mutate within the caller's transaction; the caller flushes.
 */
final class PaymentTransitionService
{
    /**
     * Statuses this service set and has not yet seen flushed, keyed by entity. Shared by every
     * instance in the process; a WeakMap so entries disappear together with their entities.
     *
     * @var \WeakMap<Payment, PaymentStatus>|null
     */
    private static ?\WeakMap $authorizedStatuses = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        self::$authorizedStatuses ??= new \WeakMap();
    }

    /**
     * Whether this service set $payment's status to $status; a matching authorization is used up.
     */
    public function consumeAuthorizedStatus(Payment $payment, PaymentStatus $status): bool
    {
        if ((self::$authorizedStatuses[$payment] ?? null) !== $status) {
            return false;
        }
        unset(self::$authorizedStatuses[$payment]);

        return true;
    }

    /**
     * COD has no collected funds and becomes failed; paid simulated GCash/Card payments are fully refunded.
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

        $this->move($payment, $from, $to, $changedBy, $note, $now);
    }

    public function markPaid(
        Payment $payment,
        PaymentActor $changedBy,
        string $note,
        \DateTimeImmutable $now,
    ): void {
        if (PaymentStatus::PAID === $payment->getStatus()) {
            return;
        }
        if (PaymentStatus::PENDING !== $payment->getStatus()) {
            throw new \DomainException('Only a pending payment can be marked paid.');
        }
        $payment->setPaidAt($now);
        $this->move($payment, PaymentStatus::PENDING, PaymentStatus::PAID, $changedBy, $note, $now);
    }

    /**
     * Records a full or partial refund of $amount (an exact two-place decimal string) on a paid
     * or partially refunded payment and returns the resulting status.
     */
    public function refund(
        Payment $payment,
        string $amount,
        PaymentActor $changedBy,
        ?string $reason,
        \DateTimeImmutable $now,
    ): PaymentStatus {
        $from = $payment->getStatus();
        if (!in_array($from, [PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED], true)) {
            throw new \DomainException('Only paid or partially refunded payments can be refunded.');
        }
        $remaining = bcsub($payment->getAmount(), $payment->getRefundedAmount(), 2);
        if (1 !== bccomp($amount, '0', 2) || 1 === bccomp($amount, $remaining, 2)) {
            throw new \DomainException('The refund amount cannot exceed the remaining refundable balance.');
        }
        $refunded = bcadd($payment->getRefundedAmount(), $amount, 2);
        $to = -1 !== bccomp($refunded, $payment->getAmount(), 2) ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;
        $payment->setRefundedAmount($refunded)->setRefundedAt($now);
        $note = sprintf(
            '%s recorded a %s refund of PHP %s%s',
            ucfirst($changedBy->value),
            PaymentStatus::REFUNDED === $to ? 'full' : 'partial',
            $amount,
            null === $reason || '' === $reason ? '.' : ': '.$reason,
        );
        $this->move($payment, $from, $to, $changedBy, $note, $now);

        return $to;
    }

    private function move(
        Payment $payment,
        PaymentStatus $from,
        PaymentStatus $to,
        PaymentActor $changedBy,
        string $note,
        \DateTimeImmutable $now,
    ): void {
        self::$authorizedStatuses[$payment] = $to;
        $payment->setStatus($to)->setUpdatedAt($now);
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
