<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderReportListController extends AbstractController
{
    #[Route('/rider/report/list', name: 'app_rider_report_list')]
    public function index(): Response
    {
        return $this->render('rider_report_list/index.html.twig', [
            'controller_name' => 'RiderReportListController',
        ]);
    }
}
