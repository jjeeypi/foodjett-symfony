<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderStatusPageController extends AbstractController
{
    #[Route('/rider/status/page', name: 'app_rider_status_page')]
    public function index(): Response
    {
        return $this->render('rider_status_page/index.html.twig', [
            'controller_name' => 'RiderStatusPageController',
        ]);
    }
}
