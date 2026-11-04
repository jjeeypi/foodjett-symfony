<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeliveryCoverageController extends AbstractController
{
    #[Route('/delivery/coverage', name: 'app_delivery_coverage')]
    public function index(): Response
    {
        return $this->render('delivery_coverage/index.html.twig', [
            'controller_name' => 'DeliveryCoverageController',
        ]);
    }
}
