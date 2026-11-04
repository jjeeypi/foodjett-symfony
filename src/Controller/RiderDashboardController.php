<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderDashboardController extends AbstractController
{
    #[Route('/rider/dashboard', name: 'app_rider_dashboard')]
    public function index(): Response
    {
        return $this->render('rider_dashboard/index.html.twig', [
            'controller_name' => 'RiderDashboardController',
        ]);
    }
}
