<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Orders whose rider search escalated to the admins, longest wait first. */
#[IsGranted('ROLE_ADMIN')]
final class AdminUnassignedOrderController extends AbstractController
{
    #[Route('/admin/order/unassigned', name: 'app_admin_unassigned_order', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders, PaginatorInterface $paginator): Response
    {
        return $this->render('admin_unassigned_order/index.html.twig', [
            'orders' => $paginator->paginate($orders->createEscalatedRiderSearchQuery(), $request->query->getInt('page', 1), 25),
            'now' => new \DateTimeImmutable(),
        ]);
    }
}
