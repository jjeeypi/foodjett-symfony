<?php

namespace App\Repository;

use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Admin>
 */
class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    /** Administrators with their accounts, by name. */
    public function createListQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('admin')
            ->innerJoin('admin.user', 'account')->addSelect('account')->orderBy('account.name', 'ASC');
    }
}
