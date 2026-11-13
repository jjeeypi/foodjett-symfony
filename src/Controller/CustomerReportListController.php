<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerReportListController extends AbstractController
{
    #[Route('/customer/report/list', name: 'app_customer_report_list')]
    public function index(): Response
    {
        return $this->render('customer_report_list/index.html.twig', [
            'controller_name' => 'CustomerReportListController',
        ]);
    }
}
