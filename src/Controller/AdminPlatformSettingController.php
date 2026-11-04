<?php

namespace App\Controller;

use App\Entity\PlatformSetting;
use App\Entity\User;
use App\Form\PlatformSettingType;
use App\Repository\AuditLogRepository;
use App\Repository\PlatformSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fees, rider pay and timing rules read at run time. Settings are added or changed but not
 * deleted, because a missing setting silently falls back to its built-in default.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/setting')]
final class AdminPlatformSettingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
    ) {
    }

    #[Route(name: 'app_admin_platform_setting_index', methods: ['GET'])]
    public function index(PlatformSettingRepository $platformSettingRepository): Response
    {
        return $this->render('admin_platform_setting/index.html.twig', [
            'platform_settings' => $platformSettingRepository->findBy([], ['key' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_platform_setting_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $admin): Response
    {
        $platformSetting = new PlatformSetting();
        $form = $this->createForm(PlatformSettingType::class, $platformSetting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($platformSetting, null, $admin);
        }

        return $this->render('admin_platform_setting/new.html.twig', [
            'platform_setting' => $platformSetting,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_platform_setting_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, PlatformSetting $platformSetting, #[CurrentUser] User $admin): Response
    {
        $before = $platformSetting->getValue();
        $form = $this->createForm(PlatformSettingType::class, $platformSetting, ['existing' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->save($platformSetting, $before, $admin);
        }

        return $this->render('admin_platform_setting/edit.html.twig', [
            'platform_setting' => $platformSetting,
            'form' => $form,
        ]);
    }

    private function save(PlatformSetting $setting, ?string $before, User $admin): Response
    {
        $now = new \DateTimeImmutable();
        $description = trim((string) $setting->getDescription());
        $setting->setValue(trim($setting->getValue()))->setDescription('' === $description ? null : $description)
            ->setCreatedAt($setting->getCreatedAt() ?? $now)->setUpdatedAt($now);
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
        $this->audit->record($admin, 'platform_setting.updated', 'platform_setting', (string) $setting->getId(), [
            'key' => $setting->getKey(), 'value' => ['from' => $before, 'to' => $setting->getValue()],
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $setting->getKey().' saved.');

        return $this->redirectToRoute('app_admin_platform_setting_index', [], Response::HTTP_SEE_OTHER);
    }
}
