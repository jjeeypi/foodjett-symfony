<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantEarningsController extends AbstractController
{
    #[Route('/restaurant/earnings', name: 'app_restaurant_earnings')]
    public function index(): Response
    {
        return $this->render('restaurant_earnings/index.html.twig', [
            'controller_name' => 'RestaurantEarningsController',
        ]);
    }
}
