<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The restaurant's home: today's numbers and recent orders once approved; until then the
 * application status, which is the only restaurant page a pending account can open.
 */
#[IsGranted('ROLE_RESTAURANT')]
final class RestaurantDashboardController extends AbstractController
{
    #[Route('/restaurant', name: 'app_restaurant_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, OrderRepository $orders): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        if (Restaurant::APPROVAL_APPROVED !== $restaurant->getApprovalStatus()) {
            return $this->render('restaurant_dashboard/index.html.twig', ['restaurant' => $restaurant, 'stats' => null]);
        }

        $start = new \DateTimeImmutable('today');
        $end = $start->modify('+1 day');

        return $this->render('restaurant_dashboard/index.html.twig', [
            'restaurant' => $restaurant,
            'stats' => [
                'orders_today' => $orders->countPlacedForRestaurantBetween($restaurant, $start, $end),
                'revenue_today' => bcround($orders->sumDeliveredTotalForRestaurantBetween($restaurant, $start, $end), 2),
                'waiting' => $orders->countForRestaurantWithStatus($restaurant, Order::STATUS_PLACED),
                'recent' => $orders->findRecentForRestaurant($restaurant),
            ],
        ]);
    }
}
