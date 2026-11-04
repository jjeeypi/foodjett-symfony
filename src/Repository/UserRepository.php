<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Finds the account that signs in with this email (case-insensitive) or phone number.
     */
    public function findOneByLoginIdentifier(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = LOWER(:identifier)')
            ->orWhere('u.phone = :identifier')
            ->setParameter('identifier', trim($identifier))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
