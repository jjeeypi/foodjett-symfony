<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\RiderEarning;
use App\Entity\RiderPayout;
use App\Enum\RemittanceStatus;
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
    public function __construct(EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator)
    {
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
        $query = $this->entityManager->getRepository(RiderEarning::class)->createQueryBuilder('earning')
            ->innerJoin('earning.order', 'orders')->addSelect('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('earning.rider = :rider')->setParameter('rider', $this->rider())
            ->orderBy('earning.createdAt', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, $this->earningData(...)));
    }

    #[Route('/payouts', name: 'payouts', methods: ['GET'])]
    public function payouts(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(RiderPayout::class)->createQueryBuilder('payout')
            ->andWhere('payout.rider = :rider')->setParameter('rider', $this->rider())
            ->orderBy('payout.periodEnd', 'DESC');

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
            'pending_remittances' => $this->money($pending),
            'available_to_remit' => $this->money(max(0.0, (float) $rider->getCashOnHand() - $pending)),
            'at_limit' => (float) $rider->getCashOnHand() >= (float) $rider->getCashRemitLimit(),
        ]]);
    }

    #[Route('/remittances', name: 'remittances', methods: ['GET'])]
    public function remittances(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(RiderCashRemittance::class)->createQueryBuilder('remittance')
            ->andWhere('remittance.rider = :rider')->setParameter('rider', $this->rider())
            ->orderBy('remittance.createdAt', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, $this->remittanceData(...)));
    }

    #[Route('/remittances', name: 'remittance_store', methods: ['POST'])]
    public function createRemittance(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        if (!is_numeric($data['amount'] ?? null) || (float) $data['amount'] <= 0) {
            return $this->json(['message' => 'amount must be greater than zero.'], 422);
        }
        $amount = round((float) $data['amount'], 2);
        $reference = trim((string) ($data['reference_note'] ?? ''));
        if (mb_strlen($reference) > 255) {
            return $this->json(['message' => 'reference_note cannot exceed 255 characters.'], 422);
        }
        $riderId = (string) $this->rider()->getId();
        try {
            $remittance = $this->entityManager->wrapInTransaction(function () use ($riderId, $amount, $reference): RiderCashRemittance {
                $rider = $this->entityManager->find(Rider::class, $riderId, LockMode::PESSIMISTIC_WRITE);
                if (!$rider instanceof Rider) { throw $this->createNotFoundException('Rider profile not found.'); }
                $available = (float) $rider->getCashOnHand() - $this->pendingRemittanceTotal($rider);
                if ($amount > $available + 0.0001) {
                    throw new \DomainException('The remittance amount exceeds cash available after pending requests.');
                }
                $now = new \DateTimeImmutable();
                $remittance = (new RiderCashRemittance())->setRider($rider)->setAmount($this->money($amount))
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
        $value = $this->entityManager->getRepository(RiderEarning::class)->createQueryBuilder('earning')
            ->select('COALESCE(SUM(earning.totalEarned), 0)')->andWhere('earning.rider = :rider')->setParameter('rider', $this->rider())
            ->andWhere('earning.createdAt >= :since')->setParameter('since', $since)->getQuery()->getSingleScalarResult();
        return $this->money((float) $value);
    }

    private function pendingRemittanceTotal(Rider $rider): float
    {
        return (float) $this->entityManager->getRepository(RiderCashRemittance::class)->createQueryBuilder('remittance')
            ->select('COALESCE(SUM(remittance.amount), 0)')->andWhere('remittance.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('remittance.status = :status')->setParameter('status', RemittanceStatus::PENDING->value)
            ->getQuery()->getSingleScalarResult();
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

    private function money(float $amount): string { return number_format(round($amount, 2), 2, '.', ''); }
}
