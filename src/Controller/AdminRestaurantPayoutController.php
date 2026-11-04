<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminRestaurantPayoutController extends AbstractController
{
    #[Route('/admin/restaurant/payout', name: 'app_admin_restaurant_payout')]
    public function index(): Response
    {
        return $this->render('admin_restaurant_payout/index.html.twig', [
            'controller_name' => 'AdminRestaurantPayoutController',
        ]);
    }
}
