<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Form\RiderType;
use App\Repository\RiderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/rider')]
final class AdminRiderController extends AbstractController
{
    #[Route(name: 'app_admin_rider_index', methods: ['GET'])]
    public function index(RiderRepository $riderRepository): Response
    {
        return $this->render('admin_rider/index.html.twig', [
            'riders' => $riderRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_rider_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $rider = new Rider();
        $form = $this->createForm(RiderType::class, $rider);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($rider);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_rider_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_rider/new.html.twig', [
            'rider' => $rider,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_rider_show', methods: ['GET'])]
    public function show(Rider $rider): Response
    {
        return $this->render('admin_rider/show.html.twig', [
            'rider' => $rider,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_rider_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Rider $rider, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(RiderType::class, $rider);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_rider_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_rider/edit.html.twig', [
            'rider' => $rider,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_rider_delete', methods: ['POST'])]
    public function delete(Request $request, Rider $rider, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$rider->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($rider);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_rider_index', [], Response::HTTP_SEE_OTHER);
    }
}
