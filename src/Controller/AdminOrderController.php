<?php

namespace App\Controller;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Every order on the platform for admins, filtered by status and placed date. */
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderController extends AbstractController
{
    #[Route('/admin/order', name: 'app_admin_order', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders, PaginatorInterface $paginator): Response
    {
        $status = $request->query->getString('status');
        $status = in_array($status, Order::STATUSES, true) ? $status : null;
        $from = $this->date($request->query->getString('from'));
        $to = $this->date($request->query->getString('to'))?->setTime(23, 59, 59);

        return $this->render('admin_order/index.html.twig', [
            'orders' => $paginator->paginate($orders->createAdminListQuery($status, '', $from, $to), $request->query->getInt('page', 1), 25),
            'statuses' => Order::STATUSES,
            'status' => $status,
            'from' => $from,
            'to' => $to,
        ]);
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
