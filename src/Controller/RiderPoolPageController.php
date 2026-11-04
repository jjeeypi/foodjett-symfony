<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderPoolPageController extends AbstractController
{
    #[Route('/rider/pool/page', name: 'app_rider_pool_page')]
    public function index(): Response
    {
        return $this->render('rider_pool_page/index.html.twig', [
            'controller_name' => 'RiderPoolPageController',
        ]);
    }
}
