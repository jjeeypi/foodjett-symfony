<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/dashboard', name: 'api_restaurant_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantDashboardController extends AbstractRestaurantController
{
    public function __construct(
        private readonly OrderRepository $orders,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $restaurant = $this->restaurant();
        $start = new \DateTimeImmutable('today');
        $end = $start->modify('+1 day');
        $todayCount = $this->orders->countPlacedForRestaurantBetween($restaurant, $start, $end);
        $revenue = $this->orders->sumDeliveredTotalForRestaurantBetween($restaurant, $start, $end);
        $pending = $this->orders->countForRestaurantWithStatus($restaurant, Order::STATUS_PLACED);
        $recent = $this->orders->findRecentForRestaurant($restaurant);

        return $this->json([
            'today_order_count' => $todayCount,
            'today_revenue' => bcround($revenue, 2),
            'pending_orders_count' => $pending,
            'recent_orders' => array_map(static fn (Order $order): array => [
                'id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $order->getStatus(),
                'total_amount' => $order->getTotalAmount(), 'payment_method' => $order->getPaymentMethod(),
                'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
            ], $recent),
        ]);
    }
}
