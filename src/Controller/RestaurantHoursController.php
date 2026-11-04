<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantHoursController extends AbstractController
{
    #[Route('/restaurant/hours', name: 'app_restaurant_hours')]
    public function index(): Response
    {
        return $this->render('restaurant_hours/index.html.twig', [
            'controller_name' => 'RestaurantHoursController',
        ]);
    }
}
