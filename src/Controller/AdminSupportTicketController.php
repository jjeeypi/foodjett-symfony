<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminSupportTicketController extends AbstractController
{
    #[Route('/admin/support/ticket', name: 'app_admin_support_ticket')]
    public function index(): Response
    {
        return $this->render('admin_support_ticket/index.html.twig', [
            'controller_name' => 'AdminSupportTicketController',
        ]);
    }
}
