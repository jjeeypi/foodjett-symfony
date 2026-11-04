<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Order chats for customers, restaurants and riders: the list of conversations, and the
 * customer's button to open a chat with the restaurant or the rider of an order.
 */
#[IsGranted('ROLE_USER')]
final class MessagesController extends AbstractController
{
    #[Route('/messages', name: 'app_messages', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        ConversationRepository $conversations,
        MessageRepository $messages,
        PaginatorInterface $paginator,
    ): Response {
        $page = $paginator->paginate($conversations->createForParticipantQuery($user), $request->query->getInt('page', 1), 20);
        $unread = [];
        foreach ($page as $conversation) {
            $unread[$conversation->getId()] = $messages->countUnreadInConversationFor($conversation, $user);
        }

        return $this->render('messages/index.html.twig', [
            'conversations' => $page,
            'unread' => $unread,
        ]);
    }

    /** Opens (or reuses) the order's chat with its restaurant or its rider. */
    #[IsGranted('ROLE_CUSTOMER')]
    #[Route('/orders/{id}/messages/{type}', name: 'app_conversation_start', methods: ['POST'], requirements: [
        'id' => '\d+',
        'type' => Conversation::TYPE_CUSTOMER_RESTAURANT.'|'.Conversation::TYPE_CUSTOMER_RIDER,
    ])]
    public function start(
        string $id,
        string $type,
        Request $request,
        #[CurrentUser] User $user,
        OrderRepository $orders,
        ConversationRepository $conversations,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('chat'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $customer = $user->getCustomer() ?? throw $this->createAccessDeniedException();
        $order = $orders->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException('Order not found.');

        if (Conversation::TYPE_CUSTOMER_RIDER === $type && null === $order->getRider()) {
            $this->addFlash('error', 'A rider has not been assigned to this order yet.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }
        if (Conversation::TYPE_CUSTOMER_RESTAURANT === $type && null === $order->getAcceptedAt()) {
            $this->addFlash('error', 'You can message the restaurant once it accepts the order.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }

        $conversation = $conversations->forOrder($order, $type);
        $entityManager->flush();

        return $this->redirectToRoute('app_conversation', ['id' => $conversation->getId()]);
    }
}
