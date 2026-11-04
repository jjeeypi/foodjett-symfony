<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerAddress>
 */
class CustomerAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerAddress::class);
    }

    /**
     * The customer's addresses, default first, then newest.
     *
     * @return list<CustomerAddress>
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('address')
            ->andWhere('address.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('address.isDefault', 'DESC')->addOrderBy('address.createdAt', 'DESC')->getQuery()->getResult();
    }

    public function findLatestForCustomer(Customer $customer): ?CustomerAddress
    {
        return $this->createQueryBuilder('candidate')
            ->andWhere('candidate.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('candidate.createdAt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function findOneForCustomer(int|string $id, Customer $customer): ?CustomerAddress
    {
        return $this->createQueryBuilder('address')
            ->andWhere('address.id = :id')->setParameter('id', $id)
            ->andWhere('address.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getOneOrNullResult();
    }

    /** Clears the default flag on every address of the customer. */
    public function clearDefaultForCustomer(int|string|null $customerId): void
    {
        $this->getEntityManager()->createQueryBuilder()->update(CustomerAddress::class, 'address')->set('address.isDefault', ':false')
            ->andWhere('IDENTITY(address.customer) = :customer')->setParameter('false', false)->setParameter('customer', $customerId)
            ->getQuery()->execute();
    }
}
