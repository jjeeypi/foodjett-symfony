<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUnassignedOrderController extends AbstractController
{
    #[Route('/admin/unassigned/order', name: 'app_admin_unassigned_order')]
    public function index(): Response
    {
        return $this->render('admin_unassigned_order/index.html.twig', [
            'controller_name' => 'AdminUnassignedOrderController',
        ]);
    }
}
