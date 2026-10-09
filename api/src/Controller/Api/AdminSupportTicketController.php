<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SupportTicket;
use App\Entity\User;
use App\Enum\SupportTicketStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/support-tickets', name: 'api_admin_support_tickets_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSupportTicketController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(SupportTicket::class)->createQueryBuilder('ticket')
            ->innerJoin('ticket.user', 'account')->addSelect('account')->orderBy('ticket.createdAt', 'DESC');
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = SupportTicketStatus::tryFrom($statusValue);
            if (null === $status) { return $this->json(['message' => 'Invalid status.'], 422); }
            $query->andWhere('ticket.status = :status')->setParameter('status', $status->value);
        }
        return $this->json($this->paginator->paginate($query, $request, fn (SupportTicket $ticket): array => $this->normalize($ticket)));
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(SupportTicket $ticket, Request $request): JsonResponse
    {
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['message' => 'The request body must contain valid JSON.'], 400); }
        $status = SupportTicketStatus::tryFrom((string) ($data['status'] ?? ''));
        if (null === $status) { return $this->json(['message' => 'status must be open, in_progress, or closed.'], 422); }
        $before = $ticket->getStatus();
        $ticket->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $admin = $this->getUser();
        if (!$admin instanceof User) { throw $this->createAccessDeniedException(); }
        $this->audit->record($admin, 'support_ticket.status_updated', 'support_ticket', (string) $ticket->getId(), ['status' => ['from' => $before->value, 'to' => $status->value]]);
        $this->entityManager->flush();
        return $this->json(['ticket' => $this->normalize($ticket)]);
    }

    /** @return array<string, mixed> */
    private function normalize(SupportTicket $ticket): array
    {
        return ['id' => $ticket->getId(), 'user' => ['id' => $ticket->getUser()->getId(), 'name' => $ticket->getUser()->getName(), 'email' => $ticket->getUser()->getEmail()],
            'subject' => $ticket->getSubject(), 'message' => $ticket->getMessage(), 'status' => $ticket->getStatus()->value,
            'created_at' => $ticket->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updated_at' => $ticket->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'reply_capability' => false];
    }
}
