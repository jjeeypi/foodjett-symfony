<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminRiderPayoutController extends AbstractController
{
    #[Route('/admin/rider/payout', name: 'app_admin_rider_payout')]
    public function index(): Response
    {
        return $this->render('admin_rider_payout/index.html.twig', [
            'controller_name' => 'AdminRiderPayoutController',
        ]);
    }
}
