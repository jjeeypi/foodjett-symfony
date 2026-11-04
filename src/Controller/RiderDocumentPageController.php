<?php

namespace App\Controller;

use App\Entity\RiderDocument;
use App\Entity\User;
use App\Form\DocumentUploadFormType;
use App\Repository\RiderDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rider's application documents, open to pending and rejected riders too. Only missing or
 * rejected documents can be uploaded; files are private and served only to their owner.
 */
#[IsGranted('ROLE_RIDER')]
#[Route('/rider/documents')]
final class RiderDocumentPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.private_upload_dir%')]
        private readonly string $privateUploadDir,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
    ) {
    }

    #[Route('', name: 'app_rider_documents', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $rider = $user->getRider() ?? throw $this->createAccessDeniedException();
        $documents = [];
        foreach ($rider->getDocuments() as $document) {
            $documents[$document->getType()] = $document;
        }
        $uploadable = array_values(array_filter(
            RiderDocument::TYPES,
            static fn (string $type): bool => !isset($documents[$type]) || RiderDocument::STATUS_REJECTED === $documents[$type]->getStatus(),
        ));

        $form = [] === $uploadable ? null : $this->createForm(DocumentUploadFormType::class, null, ['types' => $uploadable]);
        $form?->handleRequest($request);
        if (null !== $form && $form->isSubmitted() && $form->isValid()) {
            $type = $form->get('type')->getData();
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            $now = new \DateTimeImmutable();
            $filename = bin2hex(random_bytes(20)).'.'.($file->guessExtension() ?: 'bin');
            $file->move($this->privateUploadDir.'/riders/documents', $filename);

            $document = $documents[$type] ?? null;
            $oldPath = $document?->getFilePath();
            if (null === $document) {
                $document = (new RiderDocument())->setRider($rider)->setType($type)->setCreatedAt($now);
                $rider->addDocument($document);
                $this->entityManager->persist($document);
            }
            $document->setFilePath('riders/documents/'.$filename)->setStatus(RiderDocument::STATUS_PENDING)
                ->setRejectionReason(null)->setUpdatedAt($now);
            $this->entityManager->flush();
            if (null !== $oldPath) {
                @unlink($this->absolutePath($oldPath));
            }
            $this->addFlash('success', 'Document sent for review.');

            return $this->redirectToRoute('app_rider_documents', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('rider_document_page/index.html.twig', [
            'documents' => $documents,
            'types' => RiderDocument::TYPES,
            'form' => $form,
        ], new Response(status: null !== $form && $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/file', name: 'app_rider_document_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(string $id, #[CurrentUser] User $user, RiderDocumentRepository $documents): Response
    {
        $rider = $user->getRider() ?? throw $this->createAccessDeniedException();
        $document = $documents->findOneBy(['id' => $id, 'rider' => $rider]) ?? throw $this->createNotFoundException('Document not found.');
        $path = $this->absolutePath($document->getFilePath());
        if (!is_file($path)) {
            throw $this->createNotFoundException('The file is missing.');
        }

        return (new BinaryFileResponse($path))->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $document->getType().'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    private function absolutePath(string $stored): string
    {
        if (str_contains($stored, '..')) {
            throw $this->createNotFoundException();
        }

        return str_starts_with($stored, 'uploads/')
            ? $this->publicUploadDir.'/'.substr($stored, strlen('uploads/'))
            : $this->privateUploadDir.'/'.$stored;
    }
}
