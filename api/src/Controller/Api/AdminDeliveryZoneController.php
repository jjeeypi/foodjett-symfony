<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\DeliveryZone;
use App\Entity\User;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/delivery-zones', name: 'api_admin_delivery_zones_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDeliveryZoneController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(DeliveryZone::class)->createQueryBuilder('zone')->orderBy('zone.name', 'ASC');
        return $this->json($this->paginator->paginate($query, $request, fn (DeliveryZone $zone): array => $this->normalize($zone)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $validated = $this->validateZone($data, false);
        if ($validated instanceof JsonResponse) { return $validated; }
        $now = new \DateTimeImmutable();
        $zone = (new DeliveryZone())->setName($validated['name'])->setPolygon($validated['polygon'])->setIsActive($validated['is_active'])
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($zone);
        $this->entityManager->flush();
        $this->audit->record($this->admin(), 'delivery_zone.created', 'delivery_zone', (string) $zone->getId(), $this->normalize($zone));
        $this->entityManager->flush();
        return $this->json(['zone' => $this->normalize($zone)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(DeliveryZone $zone, Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $validated = $this->validateZone($data, true, $zone);
        if ($validated instanceof JsonResponse) { return $validated; }
        $before = $this->normalize($zone);
        $zone->setName($validated['name'])->setPolygon($validated['polygon'])->setIsActive($validated['is_active'])->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'delivery_zone.updated', 'delivery_zone', (string) $zone->getId(), ['before' => $before, 'after' => $this->normalize($zone)]);
        $this->entityManager->flush();
        return $this->json(['zone' => $this->normalize($zone)]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(DeliveryZone $zone): JsonResponse
    {
        $before = $zone->isActive();
        $zone->setIsActive(!$before)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'delivery_zone.toggled', 'delivery_zone', (string) $zone->getId(), ['is_active' => ['from' => $before, 'to' => $zone->isActive()]]);
        $this->entityManager->flush();
        return $this->json(['zone' => $this->normalize($zone)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(DeliveryZone $zone): JsonResponse
    {
        $id = (string) $zone->getId();
        $before = $this->normalize($zone);
        $this->audit->record($this->admin(), 'delivery_zone.deleted', 'delivery_zone', $id, $before);
        $this->entityManager->remove($zone);
        $this->entityManager->flush();
        return $this->json(null, 204);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function body(Request $request): array|JsonResponse
    {
        try { return $request->toArray(); } catch (\Throwable) { return $this->json(['message' => 'The request body must contain valid JSON.'], 400); }
    }

    /** @param array<string, mixed> $data
     * @return array{name: string, polygon: array{type: string, center: array{float, float}, radius_km: float}, is_active: bool}|JsonResponse
     */
    private function validateZone(array $data, bool $partial, ?DeliveryZone $zone = null): array|JsonResponse
    {
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : ($zone?->getName() ?? '');
        if ('' === $name || mb_strlen($name) > 255) { return $this->json(['message' => 'name is required and cannot exceed 255 characters.'], 422); }
        $existing = $zone?->getPolygon() ?? [];
        $center = $data['center'] ?? $existing['center'] ?? null;
        $radius = $data['radius_km'] ?? $existing['radius_km'] ?? null;
        if (!is_array($center) || 2 !== count($center) || !is_numeric($center[0] ?? null) || !is_numeric($center[1] ?? null)
            || (float) $center[0] < -90 || (float) $center[0] > 90 || (float) $center[1] < -180 || (float) $center[1] > 180
            || !is_numeric($radius) || (float) $radius <= 0) {
            return $this->json(['message' => 'center must be [latitude, longitude] and radius_km must be greater than zero.'], 422);
        }
        $active = array_key_exists('is_active', $data) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : ($zone?->isActive() ?? true);
        if (null === $active) { return $this->json(['message' => 'is_active must be boolean.'], 422); }
        return ['name' => $name, 'polygon' => ['type' => 'circle', 'center' => [(float) $center[0], (float) $center[1]], 'radius_km' => (float) $radius], 'is_active' => $active];
    }

    /** @return array<string, mixed> */
    private function normalize(DeliveryZone $zone): array
    {
        return ['id' => $zone->getId(), 'name' => $zone->getName(), 'polygon' => $zone->getPolygon(), 'is_active' => $zone->isActive(),
            'created_at' => $zone->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'updated_at' => $zone->getUpdatedAt()?->format(\DateTimeInterface::ATOM)];
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
