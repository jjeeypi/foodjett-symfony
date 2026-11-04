<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderDetailController extends AbstractController
{
    #[Route('/order/detail', name: 'app_order_detail')]
    public function index(): Response
    {
        return $this->render('order_detail/index.html.twig', [
            'controller_name' => 'OrderDetailController',
        ]);
    }
}
