<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantOrderListController extends AbstractController
{
    #[Route('/restaurant/order/list', name: 'app_restaurant_order_list')]
    public function index(): Response
    {
        return $this->render('restaurant_order_list/index.html.twig', [
            'controller_name' => 'RestaurantOrderListController',
        ]);
    }
}
