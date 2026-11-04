<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\RiderEarning;
use App\Entity\RiderPayout;
use App\Enum\RemittanceStatus;
use App\Repository\RiderCashRemittanceRepository;
use App\Repository\RiderEarningRepository;
use App\Repository\RiderPayoutRepository;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\ApiPaginator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider', name: 'api_rider_finance_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderFinanceController extends AbstractRiderController
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly RiderEarningRepository $earnings,
        private readonly RiderPayoutRepository $payouts,
        private readonly RiderCashRemittanceRepository $remittances,
    ) {
        parent::__construct($entityManager);
    }

    #[Route('/earnings/summary', name: 'earnings_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $week = $today->modify('monday this week');
        $month = $today->modify('first day of this month');

        return $this->json(['summary' => [
            'today' => $this->earningTotalSince($today),
            'week' => $this->earningTotalSince($week),
            'month' => $this->earningTotalSince($month),
        ]]);
    }

    #[Route('/earnings', name: 'earnings', methods: ['GET'])]
    public function earnings(Request $request): JsonResponse
    {
        $query = $this->earnings->createForRiderQuery($this->rider());

        return $this->json($this->paginator->paginate($query, $request, $this->earningData(...)));
    }

    #[Route('/payouts', name: 'payouts', methods: ['GET'])]
    public function payouts(Request $request): JsonResponse
    {
        $query = $this->payouts->createForRiderQuery($this->rider());

        return $this->json($this->paginator->paginate($query, $request, static fn (RiderPayout $payout): array => [
            'id' => $payout->getId(), 'period_start' => $payout->getPeriodStart()->format('Y-m-d'),
            'period_end' => $payout->getPeriodEnd()->format('Y-m-d'), 'total_amount' => $payout->getTotalAmount(),
            'status' => $payout->getStatus()->value, 'paid_at' => $payout->getPaidAt()?->format(\DateTimeInterface::ATOM),
        ]));
    }

    #[Route('/cash-status', name: 'cash_status', methods: ['GET'])]
    public function cashStatus(): JsonResponse
    {
        $rider = $this->rider();
        $pending = $this->pendingRemittanceTotal($rider);

        return $this->json(['cash_status' => [
            'cash_on_hand' => $rider->getCashOnHand(), 'cash_remit_limit' => $rider->getCashRemitLimit(),
            'pending_remittances' => $pending,
            'available_to_remit' => $this->atLeastZero(bcsub($rider->getCashOnHand(), $pending, 2)),
            'at_limit' => -1 !== bccomp($rider->getCashOnHand(), $rider->getCashRemitLimit(), 2),
        ]]);
    }

    #[Route('/remittances', name: 'remittances', methods: ['GET'])]
    public function remittances(Request $request): JsonResponse
    {
        $query = $this->remittances->createForRiderQuery($this->rider());

        return $this->json($this->paginator->paginate($query, $request, $this->remittanceData(...)));
    }

    #[Route('/remittances', name: 'remittance_store', methods: ['POST'])]
    public function createRemittance(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $amount = $this->plainAmount($data['amount'] ?? null);
        if (null === $amount || 1 !== bccomp($amount, '0', 2)) {
            return $this->json(['message' => 'amount must be greater than zero.'], 422);
        }
        $reference = trim((string) ($data['reference_note'] ?? ''));
        if (mb_strlen($reference) > 255) {
            return $this->json(['message' => 'reference_note cannot exceed 255 characters.'], 422);
        }
        $riderId = (string) $this->rider()->getId();
        try {
            $remittance = $this->entityManager->wrapInTransaction(function () use ($riderId, $amount, $reference): RiderCashRemittance {
                $rider = $this->entityManager->find(Rider::class, $riderId, LockMode::PESSIMISTIC_WRITE);
                if (!$rider instanceof Rider) { throw $this->createNotFoundException('Rider profile not found.'); }
                $available = bcsub($rider->getCashOnHand(), $this->pendingRemittanceTotal($rider), 2);
                if (1 === bccomp($amount, $available, 2)) {
                    throw new \DomainException('The remittance amount exceeds cash available after pending requests.');
                }
                $now = new \DateTimeImmutable();
                $remittance = (new RiderCashRemittance())->setRider($rider)->setAmount($amount)
                    ->setReferenceNote('' === $reference ? null : $reference)->setStatus(RemittanceStatus::PENDING)
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $this->entityManager->persist($remittance);
                $this->entityManager->flush();
                return $remittance;
            });
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json(['message' => 'Remittance request submitted.', 'remittance' => $this->remittanceData($remittance)], 201);
    }

    private function earningTotalSince(\DateTimeImmutable $since): string
    {
        return bcround($this->earnings->sumEarnedByRiderSince($this->rider(), $since), 2);
    }

    private function pendingRemittanceTotal(Rider $rider): string
    {
        return bcround($this->remittances->sumPendingForRider($rider), 2);
    }

    /** @return array<string, mixed> */
    private function earningData(RiderEarning $earning): array
    {
        return ['id' => $earning->getId(), 'order' => ['id' => $earning->getOrder()->getId(), 'order_number' => $earning->getOrder()->getOrderNumber(), 'restaurant_name' => $earning->getOrder()->getRestaurant()->getName()],
            'base_pay' => $earning->getBasePay(), 'distance_pay' => $earning->getDistancePay(), 'waiting_pay' => $earning->getWaitingPay(),
            'incentive_pay' => $earning->getIncentivePay(), 'tip_amount' => $earning->getTipAmount(), 'total_earned' => $earning->getTotalEarned(),
            'created_at' => $earning->getCreatedAt()?->format(\DateTimeInterface::ATOM)];
    }

    /** @return array<string, mixed> */
    private function remittanceData(RiderCashRemittance $remittance): array
    {
        return ['id' => $remittance->getId(), 'amount' => $remittance->getAmount(), 'reference_note' => $remittance->getReferenceNote(),
            'status' => $remittance->getStatus()->value, 'remitted_at' => $remittance->getRemittedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $remittance->getCreatedAt()?->format(\DateTimeInterface::ATOM)];
    }

    private function atLeastZero(string $amount): string
    {
        return -1 === bccomp($amount, '0', 2) ? '0.00' : $amount;
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

}
