<?php

namespace App\Controller;

use App\Entity\SupportTicket;
use App\Form\SupportTicketType;
use App\Repository\SupportTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/support/ticket')]
final class SupportTicketController extends AbstractController
{
    #[Route(name: 'app_support_ticket_index', methods: ['GET'])]
    public function index(SupportTicketRepository $supportTicketRepository): Response
    {
        return $this->render('support_ticket/index.html.twig', [
            'support_tickets' => $supportTicketRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_support_ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $supportTicket = new SupportTicket();
        $form = $this->createForm(SupportTicketType::class, $supportTicket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($supportTicket);
            $entityManager->flush();

            return $this->redirectToRoute('app_support_ticket_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('support_ticket/new.html.twig', [
            'support_ticket' => $supportTicket,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_support_ticket_show', methods: ['GET'])]
    public function show(SupportTicket $supportTicket): Response
    {
        return $this->render('support_ticket/show.html.twig', [
            'support_ticket' => $supportTicket,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_support_ticket_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SupportTicket $supportTicket, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SupportTicketType::class, $supportTicket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_support_ticket_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('support_ticket/edit.html.twig', [
            'support_ticket' => $supportTicket,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_support_ticket_delete', methods: ['POST'])]
    public function delete(Request $request, SupportTicket $supportTicket, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$supportTicket->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($supportTicket);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_support_ticket_index', [], Response::HTTP_SEE_OTHER);
    }
}
