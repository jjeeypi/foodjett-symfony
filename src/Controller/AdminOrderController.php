<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOrderController extends AbstractController
{
    #[Route('/admin/order', name: 'app_admin_order')]
    public function index(): Response
    {
        return $this->render('admin_order/index.html.twig', [
            'controller_name' => 'AdminOrderController',
        ]);
    }
}
