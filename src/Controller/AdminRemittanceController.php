<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminRemittanceController extends AbstractController
{
    #[Route('/admin/remittance', name: 'app_admin_remittance')]
    public function index(): Response
    {
        return $this->render('admin_remittance/index.html.twig', [
            'controller_name' => 'AdminRemittanceController',
        ]);
    }
}
