<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantProfileController extends AbstractController
{
    #[Route('/restaurant/profile', name: 'app_restaurant_profile')]
    public function index(): Response
    {
        return $this->render('restaurant_profile/index.html.twig', [
            'controller_name' => 'RestaurantProfileController',
        ]);
    }
}
