<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\RestaurantPayoutRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sales from delivered orders for today, this week and this month (subtotal minus the
 * platform commission), and the payouts sent to the restaurant.
 */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantEarningsController extends AbstractController
{
    #[Route('/restaurant/earnings', name: 'app_restaurant_earnings', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        OrderRepository $orders,
        RestaurantPayoutRepository $payouts,
        PaginatorInterface $paginator,
    ): Response {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $now = new \DateTimeImmutable();
        $today = $now->setTime(0, 0);

        return $this->render('restaurant_earnings/index.html.twig', [
            'periods' => [
                'Today' => $this->period($orders, $restaurant, $today, $now),
                'This week' => $this->period($orders, $restaurant, $today->modify('monday this week'), $now),
                'This month' => $this->period($orders, $restaurant, $today->modify('first day of this month'), $now),
            ],
            'payouts' => $paginator->paginate($payouts->createForRestaurantQuery($restaurant), $request->query->getInt('page', 1), 20),
        ]);
    }

    /** @return array{orders: int, gross: string, commission: string, net: string} */
    private function period(OrderRepository $orders, Restaurant $restaurant, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $delivered = $orders->findDeliveredForRestaurantBetween($restaurant, $start, $end);
        $gross = '0.00';
        $commission = '0.00';
        foreach ($delivered as $order) {
            $gross = bcadd($gross, $order->getSubtotal(), 2);
            $commission = bcadd($commission, $order->getCommissionAmount() ?? '0.00', 2);
        }

        return ['orders' => count($delivered), 'gross' => $gross, 'commission' => $commission, 'net' => bcsub($gross, $commission, 2)];
    }
}
