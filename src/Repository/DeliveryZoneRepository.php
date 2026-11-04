<?php

namespace App\Repository;

use App\Entity\DeliveryZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryZone>
 */
class DeliveryZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryZone::class);
    }

    /** Delivery zones by name. */
    public function createListQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('zone')->orderBy('zone.name', 'ASC');
    }
}
