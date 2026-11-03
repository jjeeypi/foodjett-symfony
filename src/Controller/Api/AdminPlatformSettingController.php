<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PlatformSetting;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/platform-settings', name: 'api_admin_platform_settings_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPlatformSettingController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var list<PlatformSetting> $settings */
        $settings = $this->entityManager->getRepository(PlatformSetting::class)->findBy([], ['key' => 'ASC']);
        return $this->json(['settings' => array_map(fn (PlatformSetting $setting): array => $this->normalize($setting), $settings)]);
    }

    #[Route('', name: 'update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['message' => 'The request body must contain valid JSON.'], 400); }
        $values = $data['settings'] ?? $data;
        if (!is_array($values) || [] === $values) { return $this->json(['message' => 'settings must be a non-empty key-value object.'], 422); }
        $admin = $this->getUser();
        if (!$admin instanceof User) { throw $this->createAccessDeniedException(); }
        $changed = [];
        $now = new \DateTimeImmutable();
        foreach ($values as $key => $rawValue) {
            if (!is_string($key) || 1 !== preg_match('/^[a-z][a-z0-9_]{1,99}$/', $key) || !is_scalar($rawValue)) {
                return $this->json(['message' => 'Each setting must have a valid snake_case key and scalar value.'], 422);
            }
            $value = trim((string) $rawValue);
            if ('' === $value || mb_strlen($value) > 65535) { return $this->json(['message' => $key.' must have a non-empty value.'], 422); }
            if (1 === preg_match('/(?:radius|minutes|rate|amount|fee|pay|limit|threshold|compensation)/', $key) && (!is_numeric($value) || (float) $value < 0)) {
                return $this->json(['message' => $key.' must be a non-negative number.'], 422);
            }
            $setting = $this->entityManager->getRepository(PlatformSetting::class)->findOneBy(['key' => $key]);
            $before = $setting?->getValue();
            if (!$setting instanceof PlatformSetting) {
                $setting = (new PlatformSetting())->setKey($key)->setCreatedAt($now);
                $this->entityManager->persist($setting);
            }
            $setting->setValue($value)->setUpdatedAt($now);
            $changed[] = [$setting, $before];
        }
        $this->entityManager->flush();
        foreach ($changed as [$setting, $before]) {
            $this->audit->record($admin, 'platform_setting.updated', 'platform_setting', (string) $setting->getId(), [
                'key' => $setting->getKey(), 'value' => ['from' => $before, 'to' => $setting->getValue()],
            ]);
        }
        $this->entityManager->flush();

        return $this->index();
    }

    /** @return array<string, mixed> */
    private function normalize(PlatformSetting $setting): array
    {
        return ['id' => $setting->getId(), 'key' => $setting->getKey(), 'value' => $setting->getValue(), 'description' => $setting->getDescription()];
    }
}
