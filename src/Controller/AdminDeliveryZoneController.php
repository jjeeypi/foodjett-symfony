<?php

namespace App\Controller;

use App\Entity\DeliveryZone;
use App\Entity\User;
use App\Form\DeliveryZoneType;
use App\Repository\AuditLogRepository;
use App\Repository\DeliveryZoneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where Foodjett delivers. Checkout refuses addresses outside every active zone;
 * with no active zone at all, everywhere is covered.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/delivery-zone')]
final class AdminDeliveryZoneController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
    ) {
    }

    #[Route(name: 'app_admin_delivery_zone_index', methods: ['GET'])]
    public function index(DeliveryZoneRepository $deliveryZoneRepository): Response
    {
        return $this->render('admin_delivery_zone/index.html.twig', [
            'delivery_zones' => $deliveryZoneRepository->createListQuery()->getQuery()->getResult(),
        ]);
    }

    #[Route('/new', name: 'app_admin_delivery_zone_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $admin): Response
    {
        $deliveryZone = (new DeliveryZone())->setIsActive(true);
        $form = $this->createForm(DeliveryZoneType::class, $deliveryZone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $deliveryZone->setName(trim($deliveryZone->getName()))->setCreatedAt($now)->setUpdatedAt($now);
            $this->entityManager->persist($deliveryZone);
            $this->entityManager->flush();
            $this->audit->record($admin, 'delivery_zone.created', 'delivery_zone', (string) $deliveryZone->getId(), $this->snapshot($deliveryZone));
            $this->entityManager->flush();
            $this->addFlash('success', 'Zone created.');

            return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_delivery_zone/new.html.twig', [
            'delivery_zone' => $deliveryZone,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_delivery_zone_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(DeliveryZone $deliveryZone): Response
    {
        return $this->render('admin_delivery_zone/show.html.twig', [
            'delivery_zone' => $deliveryZone,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_delivery_zone_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, DeliveryZone $deliveryZone, #[CurrentUser] User $admin): Response
    {
        $before = $this->snapshot($deliveryZone);
        $form = $this->createForm(DeliveryZoneType::class, $deliveryZone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $deliveryZone->setName(trim($deliveryZone->getName()))->setUpdatedAt(new \DateTimeImmutable());
            $this->audit->record($admin, 'delivery_zone.updated', 'delivery_zone', (string) $deliveryZone->getId(), ['before' => $before, 'after' => $this->snapshot($deliveryZone)]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Zone updated.');

            return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_delivery_zone/edit.html.twig', [
            'delivery_zone' => $deliveryZone,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle', name: 'app_admin_delivery_zone_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(Request $request, DeliveryZone $deliveryZone, #[CurrentUser] User $admin): Response
    {
        if (!$this->isCsrfTokenValid('toggle'.$deliveryZone->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $before = $deliveryZone->isActive();
        $deliveryZone->setIsActive(!$before)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'delivery_zone.toggled', 'delivery_zone', (string) $deliveryZone->getId(), ['is_active' => ['from' => $before, 'to' => !$before]]);
        $this->entityManager->flush();
        $this->addFlash('success', $deliveryZone->getName().($before ? ' switched off.' : ' switched on.'));

        return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'app_admin_delivery_zone_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, DeliveryZone $deliveryZone, #[CurrentUser] User $admin): Response
    {
        if ($this->isCsrfTokenValid('delete'.$deliveryZone->getId(), $request->getPayload()->getString('_token'))) {
            $this->audit->record($admin, 'delivery_zone.deleted', 'delivery_zone', (string) $deliveryZone->getId(), $this->snapshot($deliveryZone));
            $this->entityManager->remove($deliveryZone);
            $this->entityManager->flush();
            $this->addFlash('success', 'Zone deleted.');
        }

        return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, mixed> */
    private function snapshot(DeliveryZone $zone): array
    {
        return ['name' => $zone->getName(), 'polygon' => $zone->getPolygon(), 'is_active' => $zone->isActive()];
    }
}
