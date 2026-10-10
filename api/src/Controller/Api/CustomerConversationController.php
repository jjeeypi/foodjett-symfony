<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Enum\ConversationType;
use App\Event\MessageSentEvent;
use App\Security\Voter\ConversationVoter;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/api/customer/conversations', name: 'api_customer_conversations_')]
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerConversationController extends AbstractCustomerController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $this->customer())
            ->orderBy('conversation.updatedAt', 'DESC')->addOrderBy('conversation.createdAt', 'DESC');

        return $this->json($this->paginator->paginate($query, $request, $this->conversationData(...)));
    }

    #[Route('/{id}/messages', name: 'messages', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function messages(string $id, Request $request): JsonResponse
    {
        $conversation = $this->ownedConversation($id);
        $this->denyAccessUnlessGranted(ConversationVoter::VIEW, $conversation);
        $query = $this->entityManager->getRepository(Message::class)->createQueryBuilder('message')
            ->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->orderBy('message.createdAt', 'ASC')->addOrderBy('message.id', 'ASC');

        $result = $this->paginator->paginate($query, $request, $this->messageData(...));
        $result['conversation'] = $this->conversationData($conversation);

        return $this->json($result);
    }

    #[Route('/{id}/messages', name: 'send', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function send(string $id, Request $request): JsonResponse
    {
        $conversation = $this->ownedConversation($id);
        if ($conversation->isClosed()) {
            return $this->json(['message' => 'This conversation is closed and is now read-only.'], 409);
        }
        $this->denyAccessUnlessGranted(ConversationVoter::SEND, $conversation);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $body = trim((string) ($data['body'] ?? ''));
        if ('' === $body || mb_strlen($body) > 1000) {
            return $this->json(['message' => 'body must be between 1 and 1000 characters.'], 422);
        }
        $since = new \DateTimeImmutable('-1 minute');
        $sentRecently = (int) $this->entityManager->getRepository(Message::class)->createQueryBuilder('message')
            ->select('COUNT(message.id)')->andWhere('message.sender = :sender')->setParameter('sender', $this->customerUser())
            ->andWhere('message.createdAt >= :since')->setParameter('since', $since)->getQuery()->getSingleScalarResult();
        if ($sentRecently >= 20) {
            return $this->json(['message' => 'Too many messages. Please wait before sending another.'], 429);
        }

        $now = new \DateTimeImmutable();
        $message = (new Message())->setConversation($conversation)->setSender($this->customerUser())->setBody($body)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $conversation->addMessage($message)->setUpdatedAt($now);
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        $recipient = ConversationType::CUSTOMER_RESTAURANT === $conversation->getType()
            ? $conversation->getOrder()->getRestaurant()->getUser()
            : $conversation->getOrder()->getRider()?->getUser();
        if (null !== $recipient) {
            $this->eventDispatcher->dispatch(new MessageSentEvent(
                messageId: (string) $message->getId(),
                conversationId: (string) $conversation->getId(),
                senderUserId: (string) $this->customerUser()->getId(),
                recipientUserId: (string) $recipient->getId(),
                sentAt: $now,
            ));
        }

        return $this->json(['message' => $this->messageData($message)], 201);
    }

    #[Route('/{id}/mark-read', name: 'mark_read', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function markRead(string $id): JsonResponse
    {
        $conversation = $this->ownedConversation($id);
        $this->denyAccessUnlessGranted(ConversationVoter::VIEW, $conversation);
        $now = new \DateTimeImmutable();
        $updated = $this->entityManager->createQueryBuilder()->update(Message::class, 'message')
            ->set('message.readAt', ':now')->setParameter('now', $now)
            ->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->andWhere('message.sender != :user')->setParameter('user', $this->customerUser())
            ->andWhere('message.readAt IS NULL')->getQuery()->execute();

        return $this->json(['message' => 'Conversation marked as read.', 'updated' => (int) $updated, 'read_at' => $now->format(\DateTimeInterface::ATOM)]);
    }

    private function ownedConversation(string $id): Conversation
    {
        $conversation = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('conversation.id = :id')->setParameter('id', $id)
            ->andWhere('orders.customer = :customer')->setParameter('customer', $this->customer())
            ->getQuery()->getOneOrNullResult();
        if (!$conversation instanceof Conversation) {
            throw $this->createNotFoundException('Conversation not found.');
        }

        return $conversation;
    }

    /** @return array<string, mixed> */
    private function conversationData(Conversation $conversation): array
    {
        $lastMessage = $this->entityManager->getRepository(Message::class)->findOneBy(['conversation' => $conversation], ['createdAt' => 'DESC', 'id' => 'DESC']);
        $unreadCount = (int) $this->entityManager->getRepository(Message::class)->createQueryBuilder('message')
            ->select('COUNT(message.id)')->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->andWhere('message.sender != :user')->setParameter('user', $this->customerUser())
            ->andWhere('message.readAt IS NULL')->getQuery()->getSingleScalarResult();
        $order = $conversation->getOrder();
        $counterpart = ConversationType::CUSTOMER_RESTAURANT === $conversation->getType()
            ? ['type' => 'restaurant', 'id' => $order->getRestaurant()->getId(), 'name' => $order->getRestaurant()->getName()]
            : ['type' => 'rider', 'id' => $order->getRider()?->getId(), 'name' => $order->getRider()?->getUser()->getName()];

        return [
            'id' => $conversation->getId(), 'type' => $conversation->getType()->value,
            'order' => ['id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'restaurant_name' => $order->getRestaurant()->getName()],
            'counterpart' => $counterpart, 'is_closed' => $conversation->isClosed(),
            'closed_at' => $conversation->getClosedAt()?->format(\DateTimeInterface::ATOM), 'unread_count' => $unreadCount,
            'last_message' => $lastMessage instanceof Message ? $this->messageData($lastMessage) : null,
            'updated_at' => $conversation->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function messageData(Message $message): array
    {
        return [
            'id' => $message->getId(), 'conversation_id' => $message->getConversation()->getId(),
            'sender' => ['id' => $message->getSender()->getId(), 'name' => $message->getSender()->getName()],
            'body' => $message->getBody(), 'read_at' => $message->getReadAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $message->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
