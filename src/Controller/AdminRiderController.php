<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\RiderDocument;
use App\Entity\User;
use App\Form\AdminReasonFormType;
use App\Form\RiderType;
use App\Repository\AuditLogRepository;
use App\Repository\RiderRepository;
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
 * Riders for admins: applications and their documents, approval, suspension and the
 * vehicle and cash limit. Every decision is written to the audit log.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/rider')]
final class AdminRiderController extends AbstractController
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

    #[Route(name: 'app_admin_rider_index', methods: ['GET'])]
    public function index(Request $request, RiderRepository $riderRepository, PaginatorInterface $paginator): Response
    {
        $approval = $request->query->getString('approval');
        $approval = in_array($approval, Rider::APPROVAL_STATUSES, true) ? $approval : null;
        $search = trim($request->query->getString('search'));

        return $this->render('admin_rider/index.html.twig', [
            'riders' => $paginator->paginate($riderRepository->createAdminListQuery($approval, $search), $request->query->getInt('page', 1), 20),
            'approval' => $approval,
            'search' => $search,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_rider_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Rider $rider): Response
    {
        return $this->renderShow($rider);
    }

    #[Route('/{id}/edit', name: 'app_admin_rider_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Rider $rider, #[CurrentUser] User $admin): Response
    {
        $before = $this->snapshot($rider);
        $form = $this->createForm(RiderType::class, $rider);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plate = trim((string) $rider->getPlateNumber());
            $rider->setPlateNumber('' === $plate ? null : $plate)
                ->setCashRemitLimit(bcround($rider->getCashRemitLimit(), 2))
                ->setUpdatedAt(new \DateTimeImmutable());
            $changes = [];
            foreach ($this->snapshot($rider) as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = ['from' => $before[$field], 'to' => $value];
                }
            }
            if ([] !== $changes) {
                $this->audit->record($admin, 'rider.updated', 'rider', (string) $rider->getId(), $changes);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Rider updated.');

            return $this->redirectToRoute('app_admin_rider_show', ['id' => $rider->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_rider/edit.html.twig', [
            'rider' => $rider,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/approve', name: 'app_admin_rider_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Request $request, Rider $rider, #[CurrentUser] User $admin): Response
    {
        $this->assertToken($request, 'approve'.$rider->getId());
        $before = $rider->getApprovalStatus();
        $rider->setApprovalStatus(Rider::APPROVAL_APPROVED)->setRejectionReason(null)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'rider.approved', 'rider', (string) $rider->getId(), [
            'approval_status' => ['from' => $before, 'to' => Rider::APPROVAL_APPROVED],
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $rider->getUser()->getName().' is approved.');

        return $this->redirectToRoute('app_admin_rider_show', ['id' => $rider->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/reject', name: 'app_admin_rider_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Request $request, Rider $rider, #[CurrentUser] User $admin): Response
    {
        $form = $this->reasonForm('reject')->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($rider, reject: $form);
        }
        $reason = trim((string) $form->get('reason')->getData());
        $before = $rider->getApprovalStatus();
        $rider->setApprovalStatus(Rider::APPROVAL_REJECTED)->setRejectionReason($reason)
            ->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'rider.rejected', 'rider', (string) $rider->getId(), [
            'approval_status' => ['from' => $before, 'to' => Rider::APPROVAL_REJECTED],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', $rider->getUser()->getName().' was rejected.');

        return $this->redirectToRoute('app_admin_rider_show', ['id' => $rider->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Suspends or reactivates the rider's account; a suspended rider is signed out. */
    #[Route('/{id}/account/{status}', name: 'app_admin_rider_account', methods: ['POST'], requirements: ['id' => '\d+', 'status' => 'suspended|active'])]
    public function account(Request $request, Rider $rider, string $status, #[CurrentUser] User $admin): Response
    {
        $this->assertToken($request, 'account'.$rider->getId());
        $account = $rider->getUser();
        $before = $account->getStatus();
        $account->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        if (User::STATUS_SUSPENDED === $status) {
            $rider->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE);
        }
        $this->audit->record($admin, 'rider.'.(User::STATUS_ACTIVE === $status ? 'reactivated' : 'suspended'), 'rider', (string) $rider->getId(), [
            'user_status' => ['from' => $before, 'to' => $status],
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', User::STATUS_ACTIVE === $status ? 'Account reactivated.' : 'Account suspended.');

        return $this->redirectToRoute('app_admin_rider_show', ['id' => $rider->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/documents/{documentId}/verify', name: 'app_admin_rider_document_verify', methods: ['POST'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function verifyDocument(Request $request, Rider $rider, string $documentId, #[CurrentUser] User $admin): Response
    {
        $document = $this->document($rider, $documentId);
        $this->assertToken($request, 'document'.$document->getId());
        $this->reviewDocument($document, RiderDocument::STATUS_VERIFIED, null, $admin);

        return $this->redirectToRoute('app_admin_rider_show', ['id' => $rider->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/documents/{documentId}/reject', name: 'app_admin_rider_document_reject', methods: ['POST'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function rejectDocument(Request $request, Rider $rider, string $documentId, #[CurrentUser] User $admin): Response
    {
        $document = $this->document($rider, $documentId);
        $form = $this->reasonForm('document_'.$document->getId())->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($rider);
        }
        $this->reviewDocument($document, RiderDocument::STATUS_REJECTED, trim((string) $form->get('reason')->getData()), $admin);

        return $this->redirectToRoute('app_admin_rider_show', ['id' => $rider->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/documents/{documentId}/file', name: 'app_admin_rider_document_file', methods: ['GET'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function documentFile(Rider $rider, string $documentId): Response
    {
        $document = $this->document($rider, $documentId);
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

    private function renderShow(Rider $rider, ?FormInterface $reject = null): Response
    {
        $documentForms = [];
        foreach ($rider->getDocuments() as $document) {
            $documentForms[$document->getId()] = $this->reasonForm('document_'.$document->getId(), 'Why is this document rejected?')->createView();
        }

        return $this->render('admin_rider/show.html.twig', [
            'rider' => $rider,
            'reject_form' => ($reject ?? $this->reasonForm('reject', 'Why is the application rejected?'))->createView(),
            'document_forms' => $documentForms,
        ], new Response(status: null !== $reject ? 422 : 200));
    }

    private function reviewDocument(RiderDocument $document, string $status, ?string $reason, User $admin): void
    {
        $before = $document->getStatus();
        $document->setStatus($status)->setRejectionReason($reason)->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($admin, 'rider_document.'.(RiderDocument::STATUS_VERIFIED === $status ? 'verified' : 'rejected'), 'rider_document', (string) $document->getId(), [
            'status' => ['from' => $before, 'to' => $status],
            'rejection_reason' => $reason,
        ]);
        $this->entityManager->flush();
        $this->addFlash('success', 'Document '.(RiderDocument::STATUS_VERIFIED === $status ? 'verified.' : 'rejected.'));
    }

    private function reasonForm(string $name, string $label = 'Reason'): FormInterface
    {
        return $this->container->get('form.factory')->createNamed($name, AdminReasonFormType::class, null, ['reason_label' => $label]);
    }

    private function document(Rider $rider, string $id): RiderDocument
    {
        return $rider->getDocuments()->findFirst(static fn ($key, RiderDocument $document): bool => (string) $document->getId() === $id)
            ?? throw $this->createNotFoundException('Document not found.');
    }

    /** @return array<string, mixed> */
    private function snapshot(Rider $rider): array
    {
        return [
            'vehicle_type' => $rider->getVehicleType(),
            'plate_number' => $rider->getPlateNumber(),
            'cash_remit_limit' => $rider->getCashRemitLimit(),
        ];
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
    }
}
