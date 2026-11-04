<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Admin;
use App\Entity\OrderReport;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\OrderReportRepository;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/order-reports', name: 'api_admin_order_reports_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogRepository $audit,
        private readonly OrderReportRepository $reports,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $filters = ['status' => null, 'type' => null, 'against' => null];
        $allowed = ['status' => OrderReport::STATUSES, 'type' => OrderReport::TYPES, 'against' => OrderReport::AGAINST_TARGETS];
        foreach ($allowed as $parameter => $values) {
            $value = trim((string) $request->query->get($parameter, ''));
            if ('' === $value) {
                continue;
            }
            if (!in_array($value, $values, true)) {
                return $this->json(['message' => 'Invalid '.$parameter.'.'], 422);
            }
            $filters[$parameter] = $value;
        }
        $query = $this->reports->createAdminListQuery($filters['status'], $filters['type'], $filters['against']);

        return $this->json($this->paginator->paginate($query, $request, fn (OrderReport $report): array => $this->normalize($report)));
    }

    #[Route('/{id}/resolve', name: 'resolve', methods: ['POST'])]
    public function resolve(OrderReport $report, Request $request): JsonResponse
    {
        return $this->finish($report, $request, OrderReport::STATUS_RESOLVED);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(OrderReport $report, Request $request): JsonResponse
    {
        return $this->finish($report, $request, OrderReport::STATUS_REJECTED);
    }

    private function finish(OrderReport $report, Request $request, string $status): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $resolution = trim(is_string($data['resolution'] ?? null) ? $data['resolution'] : '');
        if ('' === $resolution || mb_strlen($resolution) > 65535) {
            return $this->json(['message' => 'resolution is required.'], 422);
        }
        $adminUser = $this->getUser();
        if (!$adminUser instanceof User || !$adminUser->getAdmin() instanceof Admin) {
            throw $this->createAccessDeniedException();
        }
        $before = $report->getStatus();
        $now = new \DateTimeImmutable();
        $report->setStatus($status)->setResolution($resolution)->setResolvedByAdmin($adminUser->getAdmin())
            ->setResolvedAt($now)->setUpdatedAt($now);
        $this->audit->record($adminUser, 'order_report.'.$status, 'order_report', (string) $report->getId(), [
            'status' => ['from' => $before, 'to' => $status],
            'resolution' => $resolution,
        ]);
        $this->entityManager->flush();

        return $this->json(['report' => $this->normalize($report)]);
    }

    /** @return array<string, mixed> */
    private function normalize(OrderReport $report): array
    {
        return [
            'id' => $report->getId(),
            'order' => ['id' => $report->getOrder()->getId(), 'order_number' => $report->getOrder()->getOrderNumber()],
            'reported_by' => ['id' => $report->getReportedBy()->getId(), 'name' => $report->getReportedBy()->getName()],
            'against' => $report->getAgainst(),
            'type' => $report->getType(),
            'description' => $report->getDescription(),
            'status' => $report->getStatus(),
            'resolution' => $report->getResolution(),
            'resolved_by_admin_id' => $report->getResolvedByAdmin()?->getId(),
            'resolved_at' => $report->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $report->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
