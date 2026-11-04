<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BrowseController extends AbstractController
{
    #[Route('/browse', name: 'app_browse')]
    public function index(): Response
    {
        return $this->render('browse/index.html.twig', [
            'controller_name' => 'BrowseController',
        ]);
    }
}
