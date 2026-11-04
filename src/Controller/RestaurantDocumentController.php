<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RestaurantDocumentController extends AbstractController
{
    #[Route('/restaurant/document', name: 'app_restaurant_document')]
    public function index(): Response
    {
        return $this->render('restaurant_document/index.html.twig', [
            'controller_name' => 'RestaurantDocumentController',
        ]);
    }
}
