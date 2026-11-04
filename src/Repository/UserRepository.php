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

    /**
     * Whether another account already uses this email or phone number.
     *
     * @param 'email'|'phone' $field
     */
    public function isIdentifierTakenByAnother(string $field, string $value, User $current): bool
    {
        if (!in_array($field, ['email', 'phone'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported identifier field "%s".', $field));
        }

        return null !== $this->createQueryBuilder('account')
            ->select('account.id')->andWhere(sprintf('account.%s = :value', $field))->setParameter('value', $value)
            ->andWhere('account.id != :current')->setParameter('current', $current->getId())
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
