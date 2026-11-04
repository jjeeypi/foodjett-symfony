<?php

namespace App\Repository;

use App\Entity\PlatformSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformSetting>
 */
class PlatformSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformSetting::class);
    }

    /** The stored value for $key, or $default when the setting does not exist. */
    public function string(string $key, string $default): string
    {
        return $this->findOneBy(['key' => $key])?->getValue() ?? $default;
    }

    /**
     * A non-negative amount setting as an exact decimal string rounded half-up to $scale places.
     * Non-numeric or negative values fall back to $default.
     */
    public function decimal(string $key, string $default, int $scale = 2): string
    {
        $value = trim($this->string($key, $default));
        if (!is_numeric($value) || -1 === bccomp($value, '0', $scale + 2)) {
            $value = $default;
        }

        return bcround($value, $scale);
    }

    /** A numeric setting that is not money (minutes, kilometres); non-numeric values fall back to $default. */
    public function float(string $key, float $default): float
    {
        $value = $this->string($key, (string) $default);

        return is_numeric($value) ? (float) $value : $default;
    }

//    /**
//     * @return PlatformSetting[] Returns an array of PlatformSetting objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('p.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?PlatformSetting
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
