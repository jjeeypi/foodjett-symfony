<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RiderFinancePageController extends AbstractController
{
    #[Route('/rider/finance/page', name: 'app_rider_finance_page')]
    public function index(): Response
    {
        return $this->render('rider_finance_page/index.html.twig', [
            'controller_name' => 'RiderFinancePageController',
        ]);
    }
}
