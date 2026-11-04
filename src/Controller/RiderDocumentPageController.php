<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderDocumentPageController extends AbstractController
{
    #[Route('/rider/document/page', name: 'app_rider_document_page')]
    public function index(): Response
    {
        return $this->render('rider_document_page/index.html.twig', [
            'controller_name' => 'RiderDocumentPageController',
        ]);
    }
}
