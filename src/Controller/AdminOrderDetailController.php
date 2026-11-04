<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOrderDetailController extends AbstractController
{
    #[Route('/admin/order/detail', name: 'app_admin_order_detail')]
    public function index(): Response
    {
        return $this->render('admin_order_detail/index.html.twig', [
            'controller_name' => 'AdminOrderDetailController',
        ]);
    }
}
