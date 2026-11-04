<?php

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /** Customers with their accounts, newest first, optionally matching a name, email or phone search. */
    public function createAdminListQuery(string $search = ''): QueryBuilder
    {
        $query = $this->createQueryBuilder('customer')
            ->innerJoin('customer.user', 'account')->addSelect('account')
            ->orderBy('customer.createdAt', 'DESC');
        if ('' !== $search) {
            $query->andWhere('LOWER(account.name) LIKE :search OR LOWER(COALESCE(account.email, \'\')) LIKE :search OR COALESCE(account.phone, \'\') LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $query;
    }
}
