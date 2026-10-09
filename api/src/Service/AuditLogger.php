<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AuditLogger
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $changes */
    public function record(User $admin, string $action, string $subjectType, string $subjectId, array $changes = []): AuditLog
    {
        $log = (new AuditLog())
            ->setUser($admin)
            ->setAction($action)
            ->setSubjectType($subjectType)
            ->setSubjectId($subjectId)
            ->setChanges([] === $changes ? null : $changes);
        $this->entityManager->persist($log);

        return $log;
    }
}
