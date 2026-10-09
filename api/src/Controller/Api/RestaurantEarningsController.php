<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\RestaurantPayout;
use App\Enum\OrderStatus;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant', name: 'api_restaurant_earnings_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantEarningsController extends AbstractRestaurantController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator)
    {
    }

    #[Route('/earnings/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $today = $now->setTime(0, 0);
        $week = $today->modify('monday this week');
        $month = $today->modify('first day of this month');

        return $this->json(['summary' => [
            'today' => $this->period($today, $now),
            'week' => $this->period($week, $now),
            'month' => $this->period($month, $now),
        ]]);
    }

    #[Route('/payouts', name: 'payouts', methods: ['GET'])]
    public function payouts(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(RestaurantPayout::class)->createQueryBuilder('payout')
            ->andWhere('payout.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->orderBy('payout.periodEnd', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, static fn (RestaurantPayout $payout): array => [
            'id' => $payout->getId(), 'period_start' => $payout->getPeriodStart()->format('Y-m-d'),
            'period_end' => $payout->getPeriodEnd()->format('Y-m-d'), 'gross_sales' => $payout->getGrossSales(),
            'commission_deducted' => $payout->getCommissionDeducted(), 'net_amount' => $payout->getNetAmount(),
            'status' => $payout->getStatus()->value, 'paid_at' => $payout->getPaidAt()?->format(\DateTimeInterface::ATOM),
        ]));
    }

    /** @return array{order_count: int, gross_revenue: string, commission: string, net_revenue: string} */
    private function period(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<Order> $orders */
        $orders = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->andWhere('orders.status = :status')->setParameter('status', OrderStatus::DELIVERED->value)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt <= :end')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getResult();
        $gross = 0.0;
        $commission = 0.0;
        foreach ($orders as $order) {
            $gross += (float) $order->getSubtotal();
            $commission += (float) ($order->getCommissionAmount() ?? '0.00');
        }

        return [
            'order_count' => count($orders), 'gross_revenue' => number_format($gross, 2, '.', ''),
            'commission' => number_format($commission, 2, '.', ''), 'net_revenue' => number_format($gross - $commission, 2, '.', ''),
        ];
    }
}
