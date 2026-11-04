<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantReviewsController extends AbstractController
{
    #[Route('/restaurant/reviews', name: 'app_restaurant_reviews')]
    public function index(): Response
    {
        return $this->render('restaurant_reviews/index.html.twig', [
            'controller_name' => 'RestaurantReviewsController',
        ]);
    }
}
