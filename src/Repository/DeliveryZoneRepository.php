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

    /** Whether Foodjett delivers to the point: inside an active zone, or anywhere while no zone is active. */
    public function isCovered(float $latitude, float $longitude): bool
    {
        $zones = $this->findBy(['isActive' => true]);
        if ([] === $zones) {
            return true;
        }
        foreach ($zones as $zone) {
            if ($this->contains($zone->getPolygon(), $latitude, $longitude)) {
                return true;
            }
        }

        return false;
    }

    /** Great-circle (haversine) distance between two points, in kilometres. */
    public static function distanceKm(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusKm = 6371.0;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param array<string, mixed> $geometry */
    private function contains(array $geometry, float $latitude, float $longitude): bool
    {
        $type = strtolower((string) ($geometry['type'] ?? ''));
        if ('circle' === $type) {
            $center = $geometry['center'] ?? null;
            $radius = $geometry['radius_km'] ?? null;
            if (!is_array($center) || count($center) < 2 || !is_numeric($center[0]) || !is_numeric($center[1]) || !is_numeric($radius)) {
                return false;
            }

            // Foodjett's documented circle format stores center as [latitude, longitude].
            return self::distanceKm($latitude, $longitude, (float) $center[0], (float) $center[1]) <= (float) $radius;
        }

        if ('polygon' === $type) {
            $coordinates = $geometry['coordinates'][0] ?? null;
            if (!is_array($coordinates) || count($coordinates) < 3) {
                return false;
            }

            return $this->pointInGeoJsonPolygon($longitude, $latitude, $coordinates);
        }

        return false;
    }

    /** @param list<mixed> $coordinates */
    private function pointInGeoJsonPolygon(float $x, float $y, array $coordinates): bool
    {
        $inside = false;
        $last = count($coordinates) - 1;
        for ($current = 0; $current < count($coordinates); $last = $current++) {
            $a = $coordinates[$current] ?? null;
            $b = $coordinates[$last] ?? null;
            if (!is_array($a) || !is_array($b) || !isset($a[0], $a[1], $b[0], $b[1])) {
                return false;
            }

            $ax = (float) $a[0];
            $ay = (float) $a[1];
            $bx = (float) $b[0];
            $by = (float) $b[1];
            $crosses = (($ay > $y) !== ($by > $y))
                && ($x < ($bx - $ax) * ($y - $ay) / (($by - $ay) ?: PHP_FLOAT_EPSILON) + $ax);
            if ($crosses) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
