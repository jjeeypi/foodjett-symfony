<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantReportListController extends AbstractController
{
    #[Route('/restaurant/report/list', name: 'app_restaurant_report_list')]
    public function index(): Response
    {
        return $this->render('restaurant_report_list/index.html.twig', [
            'controller_name' => 'RestaurantReportListController',
        ]);
    }
}
