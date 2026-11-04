<?php

namespace App\Controller;

use App\Entity\RestaurantDocument;
use App\Entity\User;
use App\Form\DocumentUploadFormType;
use App\Repository\RestaurantDocumentRepository;
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
 * The restaurant's application documents. Pending and rejected restaurants use this page
 * too, so it does not require approval. Only missing or rejected documents can be uploaded;
 * files are private and served only to their owner.
 */
#[IsGranted('ROLE_RESTAURANT')]
#[Route('/restaurant/documents')]
final class RestaurantDocumentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%app.private_upload_dir%')]
        private readonly string $privateUploadDir,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
    ) {
    }

    #[Route('', name: 'app_restaurant_documents', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $documents = [];
        foreach ($restaurant->getDocuments() as $document) {
            $documents[$document->getType()] = $document;
        }
        $uploadable = array_values(array_filter(
            RestaurantDocument::TYPES,
            static fn (string $type): bool => !isset($documents[$type]) || RestaurantDocument::STATUS_REJECTED === $documents[$type]->getStatus(),
        ));

        $form = [] === $uploadable ? null : $this->createForm(DocumentUploadFormType::class, null, ['types' => $uploadable]);
        $form?->handleRequest($request);
        if (null !== $form && $form->isSubmitted() && $form->isValid()) {
            $type = $form->get('type')->getData();
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            $now = new \DateTimeImmutable();
            $filename = bin2hex(random_bytes(20)).'.'.($file->guessExtension() ?: 'bin');
            $file->move($this->privateUploadDir.'/restaurants/documents', $filename);

            $document = $documents[$type] ?? null;
            $oldPath = $document?->getFilePath();
            if (null === $document) {
                $document = (new RestaurantDocument())->setRestaurant($restaurant)->setType($type)->setCreatedAt($now);
                $restaurant->addDocument($document);
                $this->entityManager->persist($document);
            }
            $document->setFilePath('restaurants/documents/'.$filename)->setStatus(RestaurantDocument::STATUS_PENDING)
                ->setRejectionReason(null)->setUpdatedAt($now);
            $this->entityManager->flush();
            if (null !== $oldPath) {
                @unlink($this->absolutePath($oldPath));
            }
            $this->addFlash('success', 'Document sent for review.');

            return $this->redirectToRoute('app_restaurant_documents', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('restaurant_document/index.html.twig', [
            'documents' => $documents,
            'types' => RestaurantDocument::TYPES,
            'form' => $form,
        ], new Response(status: null !== $form && $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/file', name: 'app_restaurant_document_file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(string $id, #[CurrentUser] User $user, RestaurantDocumentRepository $documents): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();
        $document = $documents->findOneForRestaurant($id, $restaurant) ?? throw $this->createNotFoundException('Document not found.');
        $path = $this->absolutePath($document->getFilePath());
        if (!is_file($path)) {
            throw $this->createNotFoundException('The file is missing.');
        }

        return (new BinaryFileResponse($path))->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $document->getType().'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    /** New uploads live in private storage; documents from before the move may still sit under public/uploads. */
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
