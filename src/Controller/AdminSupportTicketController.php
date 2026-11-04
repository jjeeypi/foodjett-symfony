<?php

namespace App\Controller;

use App\Entity\SupportTicket;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\SupportTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Help requests from every kind of user; admins move each one between open, in progress and closed. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/support')]
final class AdminSupportTicketController extends AbstractController
{
    #[Route(name: 'app_admin_support_ticket', methods: ['GET'])]
    public function index(Request $request, SupportTicketRepository $tickets, PaginatorInterface $paginator): Response
    {
        $status = $request->query->getString('status');
        $status = in_array($status, SupportTicket::STATUSES, true) ? $status : null;

        return $this->render('admin_support_ticket/index.html.twig', [
            'tickets' => $paginator->paginate($tickets->createAdminListQuery($status), $request->query->getInt('page', 1), 25),
            'statuses' => SupportTicket::STATUSES,
            'status' => $status,
        ]);
    }

    #[Route('/{id}/status/{status}', name: 'app_admin_support_ticket_status', methods: ['POST'], requirements: ['id' => '\d+', 'status' => 'open|in_progress|closed'])]
    public function status(Request $request, SupportTicket $ticket, string $status, #[CurrentUser] User $admin, EntityManagerInterface $entityManager, AuditLogRepository $audit): Response
    {
        if (!$this->isCsrfTokenValid('ticket'.$ticket->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $before = $ticket->getStatus();
        if ($before !== $status) {
            $ticket->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
            $audit->record($admin, 'support_ticket.status_updated', 'support_ticket', (string) $ticket->getId(), ['status' => ['from' => $before, 'to' => $status]]);
            $entityManager->flush();
        }
        $this->addFlash('success', 'Ticket marked '.str_replace('_', ' ', $status).'.');

        return $this->redirectToRoute('app_admin_support_ticket', [], Response::HTTP_SEE_OTHER);
    }
}
