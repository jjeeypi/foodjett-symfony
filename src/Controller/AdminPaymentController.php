<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminPaymentController extends AbstractController
{
    #[Route('/admin/payment', name: 'app_admin_payment')]
    public function index(): Response
    {
        return $this->render('admin_payment/index.html.twig', [
            'controller_name' => 'AdminPaymentController',
        ]);
    }
}
