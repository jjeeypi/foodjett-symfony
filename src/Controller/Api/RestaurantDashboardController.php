<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/dashboard', name: 'api_restaurant_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantDashboardController extends AbstractRestaurantController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(): JsonResponse
    {
        $restaurant = $this->restaurant();
        $start = new \DateTimeImmutable('today');
        $end = $start->modify('+1 day');
        $orderRepository = $this->entityManager->getRepository(Order::class);
        $todayCount = (int) $orderRepository->createQueryBuilder('orders')->select('COUNT(orders.id)')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.placedAt >= :start AND orders.placedAt < :end')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
        $revenue = (float) $orderRepository->createQueryBuilder('orders')->select('COALESCE(SUM(orders.totalAmount), 0)')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.status = :status')->setParameter('status', OrderStatus::DELIVERED->value)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt < :end')->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
        $pending = (int) $orderRepository->createQueryBuilder('orders')->select('COUNT(orders.id)')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->andWhere('orders.status = :status')->setParameter('status', OrderStatus::PLACED->value)
            ->getQuery()->getSingleScalarResult();
        /** @var list<Order> $recent */
        $recent = $orderRepository->createQueryBuilder('orders')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $restaurant)
            ->orderBy('orders.placedAt', 'DESC')->setMaxResults(10)->getQuery()->getResult();

        return $this->json([
            'today_order_count' => $todayCount,
            'today_revenue' => number_format($revenue, 2, '.', ''),
            'pending_orders_count' => $pending,
            'recent_orders' => array_map(static fn (Order $order): array => [
                'id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $order->getStatus()->value,
                'total_amount' => $order->getTotalAmount(), 'payment_method' => $order->getPaymentMethod()->value,
                'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
            ], $recent),
        ]);
    }
}
