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

    public function float(string $key, float $default): float
    {
        $value = $this->string($key, (string) $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
