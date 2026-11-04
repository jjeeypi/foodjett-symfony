<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrderRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The customer's orders, newest first, optionally only active or only past ones. */
#[IsGranted('ROLE_CUSTOMER')]
final class OrderListController extends AbstractController
{
    #[Route('/orders', name: 'app_orders', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, OrderRepository $orders, PaginatorInterface $paginator): Response
    {
        $customer = $user->getCustomer() ?? throw $this->createAccessDeniedException();
        $filter = $request->query->getString('filter');
        $filter = in_array($filter, ['active', 'past'], true) ? $filter : null;

        return $this->render('order_list/index.html.twig', [
            'orders' => $paginator->paginate($orders->createForCustomerQuery($customer, $filter), $request->query->getInt('page', 1), 20),
            'filter' => $filter,
        ]);
    }
}
