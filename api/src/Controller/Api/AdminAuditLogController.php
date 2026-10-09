<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AuditLog;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/audit-logs', name: 'api_admin_audit_logs_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAuditLogController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(AuditLog::class)->createQueryBuilder('log')
            ->leftJoin('log.user', 'account')->addSelect('account')->orderBy('log.createdAt', 'DESC');
        if ('' !== ($action = trim((string) $request->query->get('action', '')))) {
            $query->andWhere('log.action LIKE :action')->setParameter('action', '%'.$action.'%');
        }
        if ('' !== ($adminId = trim((string) $request->query->get('admin_id', '')))) {
            $query->andWhere('account.id = :adminId')->setParameter('adminId', $adminId);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $parameter => $operator) {
            if ('' === ($value = trim((string) $request->query->get($parameter, '')))) { continue; }
            try { $date = new \DateTimeImmutable($value.('date_to' === $parameter && 10 === strlen($value) ? ' 23:59:59' : '')); }
            catch (\Throwable) { return $this->json(['message' => $parameter.' must be a valid date.'], 422); }
            $query->andWhere('log.createdAt '.$operator.' :'.$parameter)->setParameter($parameter, $date);
        }
        return $this->json($this->paginator->paginate($query, $request, static fn (AuditLog $log): array => [
            'id' => $log->getId(), 'action' => $log->getAction(), 'subject_type' => $log->getSubjectType(), 'subject_id' => $log->getSubjectId(),
            'changes' => $log->getChanges(), 'admin' => null === $log->getUser() ? null : ['user_id' => $log->getUser()?->getId(), 'name' => $log->getUser()?->getName(), 'email' => $log->getUser()?->getEmail()],
            'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]));
    }
}
