<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlatformSetting;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlatformSettingService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function string(string $key, string $default): string
    {
        return $this->entityManager->getRepository(PlatformSetting::class)->findOneBy(['key' => $key])?->getValue()
            ?? $default;
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

    public function float(string $key, float $default): float
    {
        $value = $this->string($key, (string) $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
