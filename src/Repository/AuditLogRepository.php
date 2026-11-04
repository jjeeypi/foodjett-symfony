<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Queues an audit entry for an admin action. The caller's flush saves it
     * together with the change it describes.
     *
     * @param array<string, mixed> $changes
     */
    public function record(User $admin, string $action, string $subjectType, string $subjectId, array $changes = []): AuditLog
    {
        $log = (new AuditLog())
            ->setUser($admin)
            ->setAction($action)
            ->setSubjectType($subjectType)
            ->setSubjectId($subjectId)
            ->setChanges([] === $changes ? null : $changes);
        $this->getEntityManager()->persist($log);

        return $log;
    }

    /** Audit entries with their admin, newest first, optionally filtered by action, admin and date range. */
    public function createAdminListQuery(
        string $action = '',
        string $adminId = '',
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): QueryBuilder {
        $query = $this->createQueryBuilder('log')
            ->leftJoin('log.user', 'account')->addSelect('account')
            ->leftJoin('account.admin', 'adminProfile')->addSelect('adminProfile')
            ->orderBy('log.createdAt', 'DESC');
        if ('' !== $action) {
            $query->andWhere('log.action LIKE :action')->setParameter('action', '%'.$action.'%');
        }
        if ('' !== $adminId) {
            $query->andWhere('adminProfile.id = :adminId')->setParameter('adminId', $adminId);
        }
        if (null !== $from) {
            $query->andWhere('log.createdAt >= :date_from')->setParameter('date_from', $from);
        }
        if (null !== $to) {
            $query->andWhere('log.createdAt <= :date_to')->setParameter('date_to', $to);
        }

        return $query;
    }
}
