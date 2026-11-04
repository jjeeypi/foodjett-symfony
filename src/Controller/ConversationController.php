<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Form\MessageFormType;
use App\Message\ConversationMessageSent;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Security\Voter\ConversationVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One order chat. Participants read and write (ConversationVoter decides); opening it marks
 * the other side's messages as read. Admins may read it but not write or mark it read.
 */
#[IsGranted('ROLE_USER')]
final class ConversationController extends AbstractController
{
    private const MESSAGES_PER_MINUTE = 20;

    #[Route('/messages/{id}', name: 'app_conversation', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function index(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
        ConversationRepository $conversations,
        MessageRepository $messages,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
    ): Response {
        $conversation = $conversations->find($id) ?? throw $this->createNotFoundException('Conversation not found.');
        $this->denyAccessUnlessGranted(ConversationVoter::VIEW, $conversation);

        $form = null;
        if ($this->isGranted(ConversationVoter::SEND, $conversation)) {
            $message = new Message();
            $form = $this->createForm(MessageFormType::class, $message);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($messages->countSentBySince($user, new \DateTimeImmutable('-1 minute')) >= self::MESSAGES_PER_MINUTE) {
                    $this->addFlash('error', 'Too many messages. Please wait a moment before sending another.');

                    return $this->redirectToRoute('app_conversation', ['id' => $id]);
                }

                $now = new \DateTimeImmutable();
                $message->setConversation($conversation)->setSender($user)->setBody(trim($message->getBody()))
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $conversation->addMessage($message)->setUpdatedAt($now);
                $entityManager->persist($message);
                $entityManager->flush();

                foreach ($this->participants($conversation) as $recipient) {
                    if ($recipient !== $user) {
                        $bus->dispatch(new ConversationMessageSent(
                            messageId: (string) $message->getId(),
                            conversationId: (string) $conversation->getId(),
                            senderUserId: (string) $user->getId(),
                            recipientUserId: (string) $recipient->getId(),
                            sentAt: $now,
                        ));
                    }
                }

                return $this->redirectToRoute('app_conversation', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
        } elseif ('POST' === $request->getMethod()) {
            $this->addFlash('error', 'This conversation is closed and is now read-only.');

            return $this->redirectToRoute('app_conversation', ['id' => $id]);
        }

        if (in_array($user, $this->participants($conversation), true)) {
            $messages->markReadFor($conversation, $user, new \DateTimeImmutable());
        }

        return $this->render('conversation/index.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages->createForConversationQuery($conversation)->getQuery()->getResult(),
            'form' => $form,
        ], new Response(status: null !== $form && $form->isSubmitted() ? 422 : 200));
    }

    /** @return list<User> the customer, plus the restaurant owner or the rider */
    private function participants(Conversation $conversation): array
    {
        $order = $conversation->getOrder();
        $other = Conversation::TYPE_CUSTOMER_RESTAURANT === $conversation->getType()
            ? $order->getRestaurant()->getUser()
            : $order->getRider()?->getUser();

        return array_values(array_filter([$order->getCustomer()->getUser(), $other]));
    }
}
