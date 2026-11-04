<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderDeliveryController extends AbstractController
{
    #[Route('/rider/delivery', name: 'app_rider_delivery')]
    public function index(): Response
    {
        return $this->render('rider_delivery/index.html.twig', [
            'controller_name' => 'RiderDeliveryController',
        ]);
    }
}
