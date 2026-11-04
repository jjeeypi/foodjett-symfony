<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantDashboardController extends AbstractController
{
    #[Route('/restaurant/dashboard', name: 'app_restaurant_dashboard')]
    public function index(): Response
    {
        return $this->render('restaurant_dashboard/index.html.twig', [
            'controller_name' => 'RestaurantDashboardController',
        ]);
    }
}
