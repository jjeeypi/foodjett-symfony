<?php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Every admin decision, newest first, searchable by action and date. Entries are never edited. */
#[IsGranted('ROLE_ADMIN')]
final class AdminAuditLogController extends AbstractController
{
    #[Route('/admin/audit-log', name: 'app_admin_audit_log', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $auditLogs, PaginatorInterface $paginator): Response
    {
        $action = trim($request->query->getString('action'));
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('from')) ?: null;
        $to = (\DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('to')) ?: null)?->setTime(23, 59, 59);

        return $this->render('admin_audit_log/index.html.twig', [
            'logs' => $paginator->paginate($auditLogs->createAdminListQuery($action, '', $from, $to), $request->query->getInt('page', 1), 50),
            'action' => $action,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
