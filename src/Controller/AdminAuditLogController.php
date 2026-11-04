<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminAuditLogController extends AbstractController
{
    #[Route('/admin/audit/log', name: 'app_admin_audit_log')]
    public function index(): Response
    {
        return $this->render('admin_audit_log/index.html.twig', [
            'controller_name' => 'AdminAuditLogController',
        ]);
    }
}
