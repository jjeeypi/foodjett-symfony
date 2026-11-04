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
    #[Route('/restaurant/orders', name: 'app_restaurant_orders', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, OrderRepository $orders, PaginatorInterface $paginator): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $status = $request->query->getString('status');
        $status = in_array($status, Order::STATUSES, true) ? $status : null;
        $search = trim($request->query->getString('search'));

        return $this->render('restaurant_order_list/index.html.twig', [
            'orders' => $paginator->paginate($orders->createForRestaurantQuery($restaurant, $status, $search), $request->query->getInt('page', 1), 20),
            'status' => $status,
            'search' => $search,
            'statuses' => Order::STATUSES,
        ]);
    }
}
