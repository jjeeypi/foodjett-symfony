<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderAccountPageController extends AbstractController
{
    #[Route('/rider/account/page', name: 'app_rider_account_page')]
    public function index(): Response
    {
        return $this->render('rider_account_page/index.html.twig', [
            'controller_name' => 'RiderAccountPageController',
        ]);
    }
}
