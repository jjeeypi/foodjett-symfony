<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The restaurant's orders, newest first, by status and order number or customer. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantOrderListController extends AbstractController
{
    /** Board columns: label and the order statuses that belong in them. */
    private const STAGES = [
        'new' => ['New', [Order::STATUS_PLACED]],
        'preparing' => ['Preparing', [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING]],
        'ready' => ['Ready for pickup', [Order::STATUS_READY, Order::STATUS_FINDING_RIDER, Order::STATUS_RIDER_ASSIGNED, Order::STATUS_AT_RESTAURANT]],
        'on_the_way' => ['On the way', [Order::STATUS_PICKED_UP, Order::STATUS_ON_THE_WAY, Order::STATUS_ARRIVED]],
    ];

    #[Route('/restaurant/orders', name: 'app_restaurant_orders', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, OrderRepository $orders, PaginatorInterface $paginator): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $status = $request->query->getString('status');
        $status = in_array($status, Order::STATUSES, true) ? $status : null;
        $search = trim($request->query->getString('search'));

        // With no filter the kitchen sees its open orders as a board, one column per stage.
        if ('list' !== $request->query->getString('view') && null === $status && '' === $search) {
            return $this->render('restaurant_order_list/index.html.twig', [
                'board' => $orders->findActiveForRestaurant($restaurant),
                'stages' => self::STAGES,
                'status' => null,
                'search' => '',
                'statuses' => Order::STATUSES,
            ]);
        }

        return $this->render('restaurant_order_list/index.html.twig', [
            'board' => null,
            'orders' => $paginator->paginate($orders->createForRestaurantQuery($restaurant, $status, $search), $request->query->getInt('page', 1), 20),
            'status' => $status,
            'search' => $search,
            'statuses' => Order::STATUSES,
        ]);
    }
}
