<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantOrderDetailController extends AbstractController
{
    #[Route('/restaurant/order/detail', name: 'app_restaurant_order_detail')]
    public function index(): Response
    {
        return $this->render('restaurant_order_detail/index.html.twig', [
            'controller_name' => 'RestaurantOrderDetailController',
        ]);
    }
}
