<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOrderReportController extends AbstractController
{
    #[Route('/admin/order/report', name: 'app_admin_order_report')]
    public function index(): Response
    {
        return $this->render('admin_order_report/index.html.twig', [
            'controller_name' => 'AdminOrderReportController',
        ]);
    }
}
