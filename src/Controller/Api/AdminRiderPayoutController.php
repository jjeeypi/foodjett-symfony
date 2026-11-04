<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RestaurantPayout;
use App\Entity\RiderPayout;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\RiderEarningRepository;
use App\Repository\RiderPayoutRepository;
use App\Service\ApiPaginator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/rider-payouts', name: 'api_admin_rider_payouts_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRiderPayoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogRepository $audit,
        private readonly RiderPayoutRepository $payouts,
        private readonly RiderEarningRepository $earnings,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $status = null;
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = (in_array($statusValue, RestaurantPayout::STATUSES, true) ? $statusValue : null);
            if (null === $status) { return $this->json(['message' => 'Invalid status.'], 422); }
        }
        $query = $this->payouts->createAdminListQuery($status, trim((string) $request->query->get('rider_id', '')));

        return $this->json($this->paginator->paginate($query, $request, fn (RiderPayout $payout): array => $this->normalize($payout)));
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $period = $this->period($request);
        if ($period instanceof JsonResponse) { return $period; }
        [$periodStart, $periodEnd, $startAt, $endAt] = $period;
        $admin = $this->admin();

        /** @var array{created: list<RiderPayout>, skipped: int} $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($periodStart, $periodEnd, $startAt, $endAt, $admin): array {
            $earnings = $this->earnings->findForOrdersDeliveredBetween($startAt, $endAt);
            /** @var array<string, array{rider: \App\Entity\Rider, total: string}> $totals */
            $totals = [];
            foreach ($earnings as $earning) {
                $id = (string) $earning->getRider()->getId();
                $totals[$id] ??= ['rider' => $earning->getRider(), 'total' => '0.00'];
                $totals[$id]['total'] = bcadd($totals[$id]['total'], $earning->getTotalEarned(), 2);
            }
            $created = [];
            $skipped = 0;
            $now = new \DateTimeImmutable();
            foreach ($totals as $total) {
                $overlap = $this->payouts->countOverlapping($total['rider'], $periodStart, $periodEnd);
                if ((int) $overlap > 0) { ++$skipped; continue; }
                $payout = (new RiderPayout())->setRider($total['rider'])->setPeriodStart($periodStart)->setPeriodEnd($periodEnd)
                    ->setTotalAmount($total['total'])->setStatus(RestaurantPayout::STATUS_PENDING)
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $this->entityManager->persist($payout);
                $created[] = $payout;
            }
            $this->entityManager->flush();
            foreach ($created as $payout) {
                $this->audit->record($admin, 'rider_payout.generated', 'rider_payout', (string) $payout->getId(), [
                    'period_start' => $periodStart->format('Y-m-d'), 'period_end' => $periodEnd->format('Y-m-d'), 'total_amount' => $payout->getTotalAmount(),
                ]);
            }
            $this->entityManager->flush();
            return ['created' => $created, 'skipped' => $skipped];
        });
        if ([] === $result['created']) {
            return $this->json(['message' => $result['skipped'] > 0 ? 'All qualifying riders already have an overlapping payout period.' : 'No delivered rider earnings were found in this period.'], 422);
        }

        return $this->json(['created' => count($result['created']), 'skipped' => $result['skipped'], 'payouts' => array_map(fn (RiderPayout $payout): array => $this->normalize($payout), $result['created'])], 201);
    }

    #[Route('/{id}/mark-paid', name: 'mark_paid', methods: ['POST'])]
    public function markPaid(RiderPayout $payout): JsonResponse
    {
        $admin = $this->admin();
        /** @var RiderPayout $updated */
        $updated = $this->entityManager->wrapInTransaction(function () use ($payout, $admin): RiderPayout {
            $locked = $this->entityManager->find(RiderPayout::class, $payout->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof RiderPayout) { throw new \RuntimeException('Payout not found.'); }
            if (RestaurantPayout::STATUS_PAID === $locked->getStatus()) { return $locked; }
            $now = new \DateTimeImmutable();
            $locked->setStatus(RestaurantPayout::STATUS_PAID)->setPaidAt($now)->setUpdatedAt($now);
            $this->audit->record($admin, 'rider_payout.paid', 'rider_payout', (string) $locked->getId(), [
                'status' => ['from' => RestaurantPayout::STATUS_PENDING, 'to' => RestaurantPayout::STATUS_PAID], 'paid_at' => $now->format(\DateTimeInterface::ATOM),
            ]);
            $this->entityManager->flush();
            return $locked;
        });

        return $this->json(['payout' => $this->normalize($updated)]);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: \DateTimeImmutable, 3: \DateTimeImmutable}|JsonResponse */
    private function period(Request $request): array|JsonResponse
    {
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['message' => 'The request body must contain valid JSON.'], 400); }
        $start = $this->date((string) ($data['period_start'] ?? ''));
        $end = $this->date((string) ($data['period_end'] ?? ''));
        if (null === $start || null === $end || $end < $start || $end > new \DateTimeImmutable('today')) {
            return $this->json(['message' => 'period_start and period_end must be valid dates; the end must be on/after the start and not in the future.'], 422);
        }
        return [$start, $end, $start->setTime(0, 0), $end->setTime(23, 59, 59)];
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return false === $date || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) ? null : $date;
    }

    /** @return array<string, mixed> */
    private function normalize(RiderPayout $payout): array
    {
        return ['id' => $payout->getId(), 'rider' => ['id' => $payout->getRider()->getId(), 'name' => $payout->getRider()->getUser()->getName()],
            'period_start' => $payout->getPeriodStart()->format('Y-m-d'), 'period_end' => $payout->getPeriodEnd()->format('Y-m-d'),
            'total_amount' => $payout->getTotalAmount(), 'status' => $payout->getStatus(), 'paid_at' => $payout->getPaidAt()?->format(\DateTimeInterface::ATOM)];
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
