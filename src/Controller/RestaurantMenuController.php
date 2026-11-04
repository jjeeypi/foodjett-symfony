<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantMenuController extends AbstractController
{
    #[Route('/restaurant/menu', name: 'app_restaurant_menu')]
    public function index(): Response
    {
        return $this->render('restaurant_menu/index.html.twig', [
            'controller_name' => 'RestaurantMenuController',
        ]);
    }
}
