<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\RestaurantPayout;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PayoutStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/restaurant-payouts', name: 'api_admin_restaurant_payouts_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRestaurantPayoutController extends AbstractController
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
        $query = $this->entityManager->getRepository(RestaurantPayout::class)->createQueryBuilder('payout')
            ->innerJoin('payout.restaurant', 'restaurant')->addSelect('restaurant')
            ->orderBy('payout.periodEnd', 'DESC')->addOrderBy('payout.id', 'DESC');
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = PayoutStatus::tryFrom($statusValue);
            if (null === $status) {
                return $this->json(['message' => 'Invalid status.'], 422);
            }
            $query->andWhere('payout.status = :status')->setParameter('status', $status->value);
        }
        if ('' !== ($restaurantId = trim((string) $request->query->get('restaurant_id', '')))) {
            $query->andWhere('restaurant.id = :restaurantId')->setParameter('restaurantId', $restaurantId);
        }

        return $this->json($this->paginator->paginate($query, $request, fn (RestaurantPayout $payout): array => $this->normalize($payout)));
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $period = $this->period($request);
        if ($period instanceof JsonResponse) {
            return $period;
        }
        [$periodStart, $periodEnd, $startAt, $endAt] = $period;
        $admin = $this->admin();

        /** @var array{created: list<RestaurantPayout>, skipped: int} $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($periodStart, $periodEnd, $startAt, $endAt, $admin): array {
            /** @var list<Order> $orders */
            $orders = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
                ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
                ->andWhere('orders.status = :status')->setParameter('status', OrderStatus::DELIVERED->value)
                ->andWhere('orders.deliveredAt BETWEEN :startAt AND :endAt')->setParameter('startAt', $startAt)->setParameter('endAt', $endAt)
                ->getQuery()->getResult();
            /** @var array<string, array{restaurant: \App\Entity\Restaurant, gross: float, commission: float}> $totals */
            $totals = [];
            foreach ($orders as $order) {
                $id = (string) $order->getRestaurant()->getId();
                $totals[$id] ??= ['restaurant' => $order->getRestaurant(), 'gross' => 0.0, 'commission' => 0.0];
                $totals[$id]['gross'] += (float) $order->getSubtotal();
                $totals[$id]['commission'] += (float) ($order->getCommissionAmount() ?? '0.00');
            }
            $created = [];
            $skipped = 0;
            $now = new \DateTimeImmutable();
            foreach ($totals as $total) {
                $overlap = $this->entityManager->getRepository(RestaurantPayout::class)->createQueryBuilder('existing')
                    ->select('COUNT(existing.id)')->andWhere('existing.restaurant = :restaurant')->setParameter('restaurant', $total['restaurant'])
                    ->andWhere('existing.periodStart <= :end AND existing.periodEnd >= :start')->setParameter('start', $periodStart)->setParameter('end', $periodEnd)
                    ->getQuery()->getSingleScalarResult();
                if ((int) $overlap > 0) {
                    ++$skipped;
                    continue;
                }
                $gross = round($total['gross'], 2);
                $commission = round($total['commission'], 2);
                $payout = (new RestaurantPayout())->setRestaurant($total['restaurant'])->setPeriodStart($periodStart)->setPeriodEnd($periodEnd)
                    ->setGrossSales(number_format($gross, 2, '.', ''))->setCommissionDeducted(number_format($commission, 2, '.', ''))
                    ->setNetAmount(number_format($gross - $commission, 2, '.', ''))->setStatus(PayoutStatus::PENDING)
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $this->entityManager->persist($payout);
                $created[] = $payout;
            }
            $this->entityManager->flush();
            foreach ($created as $payout) {
                $this->audit->record($admin, 'restaurant_payout.generated', 'restaurant_payout', (string) $payout->getId(), [
                    'period_start' => $periodStart->format('Y-m-d'), 'period_end' => $periodEnd->format('Y-m-d'),
                    'gross_sales' => $payout->getGrossSales(), 'commission_deducted' => $payout->getCommissionDeducted(), 'net_amount' => $payout->getNetAmount(),
                ]);
            }
            $this->entityManager->flush();

            return ['created' => $created, 'skipped' => $skipped];
        });
        if ([] === $result['created']) {
            return $this->json(['message' => $result['skipped'] > 0 ? 'All qualifying restaurants already have an overlapping payout period.' : 'No delivered restaurant orders were found in this period.'], 422);
        }

        return $this->json(['created' => count($result['created']), 'skipped' => $result['skipped'], 'payouts' => array_map(fn (RestaurantPayout $payout): array => $this->normalize($payout), $result['created'])], 201);
    }

    #[Route('/{id}/mark-paid', name: 'mark_paid', methods: ['POST'])]
    public function markPaid(RestaurantPayout $payout): JsonResponse
    {
        $admin = $this->admin();
        /** @var RestaurantPayout $updated */
        $updated = $this->entityManager->wrapInTransaction(function () use ($payout, $admin): RestaurantPayout {
            $locked = $this->entityManager->find(RestaurantPayout::class, $payout->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof RestaurantPayout) {
                throw new \RuntimeException('Payout not found.');
            }
            if (PayoutStatus::PAID === $locked->getStatus()) {
                return $locked;
            }
            $now = new \DateTimeImmutable();
            $locked->setStatus(PayoutStatus::PAID)->setPaidAt($now)->setUpdatedAt($now);
            $this->audit->record($admin, 'restaurant_payout.paid', 'restaurant_payout', (string) $locked->getId(), [
                'status' => ['from' => PayoutStatus::PENDING->value, 'to' => PayoutStatus::PAID->value],
                'paid_at' => $now->format(\DateTimeInterface::ATOM),
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
    private function normalize(RestaurantPayout $payout): array
    {
        return ['id' => $payout->getId(), 'restaurant' => ['id' => $payout->getRestaurant()->getId(), 'name' => $payout->getRestaurant()->getName()],
            'period_start' => $payout->getPeriodStart()->format('Y-m-d'), 'period_end' => $payout->getPeriodEnd()->format('Y-m-d'),
            'gross_sales' => $payout->getGrossSales(), 'commission_deducted' => $payout->getCommissionDeducted(), 'net_amount' => $payout->getNetAmount(),
            'status' => $payout->getStatus()->value, 'paid_at' => $payout->getPaidAt()?->format(\DateTimeInterface::ATOM)];
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
