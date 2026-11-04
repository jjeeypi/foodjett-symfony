<?php

namespace App\Controller;

use App\Entity\SupportTicket;
use App\Entity\User;
use App\Form\SupportTicketType;
use App\Repository\SupportTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Support requests from any signed-in account; each account sees only its own tickets. */
#[IsGranted('ROLE_USER')]
#[Route('/support')]
final class SupportTicketController extends AbstractController
{
    #[Route(name: 'app_support_ticket_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, SupportTicketRepository $supportTicketRepository, PaginatorInterface $paginator): Response
    {
        return $this->render('support_ticket/index.html.twig', [
            'support_tickets' => $paginator->paginate($supportTicketRepository->createForUserQuery($user), $request->query->getInt('page', 1), 20),
        ]);
    }

    #[Route('/new', name: 'app_support_ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $supportTicket = new SupportTicket();
        $form = $this->createForm(SupportTicketType::class, $supportTicket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $supportTicket->setUser($user)->setSubject(trim($supportTicket->getSubject()))->setMessage(trim($supportTicket->getMessage()))
                ->setStatus(SupportTicket::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
            $entityManager->persist($supportTicket);
            $entityManager->flush();
            $this->addFlash('success', 'Your request was sent. We will get back to you.');

            return $this->redirectToRoute('app_support_ticket_show', ['id' => $supportTicket->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('support_ticket/new.html.twig', [
            'support_ticket' => $supportTicket,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_support_ticket_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $id, #[CurrentUser] User $user, SupportTicketRepository $supportTicketRepository): Response
    {
        $supportTicket = $supportTicketRepository->findOneBy(['id' => $id, 'user' => $user])
            ?? throw $this->createNotFoundException('Support ticket not found.');

        return $this->render('support_ticket/show.html.twig', [
            'support_ticket' => $supportTicket,
        ]);
    }
}
