<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\User;
use App\Form\AdminReasonFormType;
use App\Form\RestaurantType;
use App\Repository\AuditLogRepository;
use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Restaurants for admins: applications and their documents, approval, suspension and
 * editing. Every decision is written to the audit log.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/restaurant')]
final class AdminRestaurantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
        #[Autowire('%app.private_upload_dir%')]
        private readonly string $privateUploadDir,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
    ) {
    }

    #[Route(name: 'app_admin_restaurant_index', methods: ['GET'])]
    public function index(Request $request, RestaurantRepository $restaurantRepository, PaginatorInterface $paginator): Response
    {
        $approval = $request->query->getString('approval');
        $approval = in_array($approval, Restaurant::APPROVAL_STATUSES, true) ? $approval : null;
        $search = trim($request->query->getString('search'));

        return $this->render('admin_restaurant/index.html.twig', [
            'restaurants' => $paginator->paginate($restaurantRepository->createAdminListQuery($approval, $search), $request->query->getInt('page', 1), 20),
            'approval' => $approval,
            'search' => $search,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_restaurant_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Restaurant $restaurant): Response
    {
        return $this->renderShow($restaurant);
    }

    #[Route('/{id}/edit', name: 'app_admin_restaurant_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Restaurant $restaurant, #[CurrentUser] User $admin): Response
    {
        $before = $this->snapshot($restaurant);
        $form = $this->createForm(RestaurantType::class, $restaurant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $restaurant->setCommissionRate(bcround($restaurant->getCommissionRate(), 2))
                ->setMinOrderAmount(bcround($restaurant->getMinOrderAmount(), 2))
                ->setUpdatedAt(new \DateTimeImmutable());
            $changes = [];
            foreach ($this->snapshot($restaurant) as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $value];
                }
            }
            if ([] !== $changes) {
                $this->audit->record($admin, 'restaurant.updated', 'restaurant', (string) $restaurant->getId(), $changes);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Restaurant updated.');

            return $this->redirectToRoute('app_admin_restaurant_show', ['id' => $restaurant->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_restaurant/edit.html.twig', [
            'restaurant' => $restaurant,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/approve', name: 'app_admin_restaurant_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, Restaurant $restaurant, #[CurrentUser] User $admin): Response
    {
        $this->assertToken($request, 'approve'.$restaurant->getId());
        $before = $restaurant->getApprovalStatus();
        $restaurant->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setRejectionReason(null)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'restaurant.approved', 'restaurant', (string) $restaurant->getId(), [
            'approval_status' => ['from' => $before, 'to' => Restaurant::APPROVAL_APPROVED],
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $restaurant->getName().' is approved.');

        return $this->redirectToRoute('app_admin_restaurant_show', ['id' => $restaurant->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/reject', name: 'app_admin_restaurant_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Request $request, Restaurant $restaurant, #[CurrentUser] User $admin): Response
    {
        $form = $this->reasonForm('reject')->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($restaurant, reject: $form);
        }
        $reason = trim((string) $form->get('reason')->getData());
        $before = $restaurant->getApprovalStatus();
        $restaurant->setApprovalStatus(Restaurant::APPROVAL_REJECTED)->setRejectionReason($reason)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'restaurant.rejected', 'restaurant', (string) $restaurant->getId(), [
            'approval_status' => ['from' => $before, 'to' => Restaurant::APPROVAL_REJECTED],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $restaurant->getName().' was rejected.');

        return $this->redirectToRoute('app_admin_restaurant_show', ['id' => $restaurant->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Suspends or reactivates the owner's account; a suspended owner is signed out. */
    #[Route('/{id}/account/{status}', name: 'app_admin_restaurant_account', methods: ['POST'], requirements: ['id' => '\d+', 'status' => 'suspended|active'])]
    public function account(Request $request, Restaurant $restaurant, string $status, #[CurrentUser] User $admin): Response
    {
        $this->assertToken($request, 'account'.$restaurant->getId());
        $owner = $restaurant->getUser();
        $before = $owner->getStatus();
        $owner->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'restaurant.'.(User::STATUS_ACTIVE === $status ? 'reactivated' : 'suspended'), 'restaurant', (string) $restaurant->getId(), [
            'user_status' => ['from' => $before, 'to' => $status],
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', User::STATUS_ACTIVE === $status ? 'Account reactivated.' : 'Account suspended.');

        return $this->redirectToRoute('app_admin_restaurant_show', ['id' => $restaurant->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/documents/{documentId}/verify', name: 'app_admin_restaurant_document_verify', methods: ['POST'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function verifyDocument(Request $request, Restaurant $restaurant, string $documentId, #[CurrentUser] User $admin): Response
    {
        $document = $this->document($restaurant, $documentId);
        $this->assertToken($request, 'document'.$document->getId());
        $this->reviewDocument($document, RestaurantDocument::STATUS_VERIFIED, null, $admin);

        return $this->redirectToRoute('app_admin_restaurant_show', ['id' => $restaurant->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/documents/{documentId}/reject', name: 'app_admin_restaurant_document_reject', methods: ['POST'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function rejectDocument(Request $request, Restaurant $restaurant, string $documentId, #[CurrentUser] User $admin): Response
    {
        $document = $this->document($restaurant, $documentId);
        $form = $this->reasonForm('document_'.$document->getId())->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($restaurant);
        }
        $this->reviewDocument($document, RestaurantDocument::STATUS_REJECTED, trim((string) $form->get('reason')->getData()), $admin);

        return $this->redirectToRoute('app_admin_restaurant_show', ['id' => $restaurant->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/documents/{documentId}/file', name: 'app_admin_restaurant_document_file', methods: ['GET'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function documentFile(Restaurant $restaurant, string $documentId): Response
    {
        $document = $this->document($restaurant, $documentId);
        $stored = $document->getFilePath();
        if (str_contains($stored, '..')) {
            throw $this->createNotFoundException();
        }
        $path = str_starts_with($stored, 'uploads/') ? $this->publicUploadDir.'/'.substr($stored, 8) : $this->privateUploadDir.'/'.$stored;
        if (!is_file($path)) {
            throw $this->createNotFoundException('The file is missing.');
        }

        return (new BinaryFileResponse($path))->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $document->getType().'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    private function renderShow(Restaurant $restaurant, ?FormInterface $reject = null): Response
    {
        $documentForms = [];
        foreach ($restaurant->getDocuments() as $document) {
            $documentForms[$document->getId()] = $this->reasonForm('document_'.$document->getId(), 'Why is this document rejected?')->createView();
        }

        return $this->render('admin_restaurant/show.html.twig', [
            'restaurant' => $restaurant,
            'reject_form' => ($reject ?? $this->reasonForm('reject', 'Why is the application rejected?'))->createView(),
            'document_forms' => $documentForms,
        ], new Response(status: null !== $reject ? 422 : 200));
    }

    private function reviewDocument(RestaurantDocument $document, string $status, ?string $reason, User $admin): void
    {
        $before = $document->getStatus();
        $document->setStatus($status)->setRejectionReason($reason)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'restaurant_document.'.(RestaurantDocument::STATUS_VERIFIED === $status ? 'verified' : 'rejected'), 'restaurant_document', (string) $document->getId(), [
            'status' => ['from' => $before, 'to' => $status],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', 'Document '.(RestaurantDocument::STATUS_VERIFIED === $status ? 'verified.' : 'rejected.'));
    }

    private function reasonForm(string $name, string $label = 'Reason'): FormInterface
    {
        return $this->container->get('form.factory')->createNamed($name, AdminReasonFormType::class, null, ['reason_label' => $label]);
    }

    private function document(Restaurant $restaurant, string $id): RestaurantDocument
    {
        return $restaurant->getDocuments()->findFirst(static fn ($key, RestaurantDocument $document): bool => (string) $document->getId() === $id)
            ?? throw $this->createNotFoundException('Document not found.');
    }

    /** @return array<string, mixed> */
    private function snapshot(Restaurant $restaurant): array
    {
        return [
            'name' => $restaurant->getName(), 'description' => $restaurant->getDescription(), 'cuisine_type' => $restaurant->getCuisineType(),
            'address' => $restaurant->getAddress(), 'latitude' => $restaurant->getLatitude(), 'longitude' => $restaurant->getLongitude(),
            'commission_rate' => $restaurant->getCommissionRate(), 'min_order_amount' => $restaurant->getMinOrderAmount(),
            'default_prep_time_minutes' => $restaurant->getDefaultPrepTimeMinutes(), 'operating_status' => $restaurant->getOperatingStatus(),
        ];
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
    }
}
