<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Enum\ConversationType;
use App\Security\Voter\ApprovedAccountVoter;
use App\Security\Voter\ConversationVoter;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider/conversations', name: 'api_rider_conversations_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderConversationController extends AbstractRiderController
{
    public function __construct(EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator)
    {
        parent::__construct($entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $this->rider())
            ->andWhere('conversation.type = :type')->setParameter('type', ConversationType::CUSTOMER_RIDER->value)
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
        $sentRecently = (int) $this->entityManager->getRepository(Message::class)->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.sender = :sender')->setParameter('sender', $this->riderUser())
            ->andWhere('message.createdAt >= :since')->setParameter('since', new \DateTimeImmutable('-1 minute'))
            ->getQuery()->getSingleScalarResult();
        if ($sentRecently >= 20) {
            return $this->json(['message' => 'Too many messages. Please wait before sending another.'], 429);
        }

        $now = new \DateTimeImmutable();
        $message = (new Message())->setConversation($conversation)->setSender($this->riderUser())->setBody($body)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $conversation->addMessage($message)->setUpdatedAt($now);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

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
            ->andWhere('message.sender != :user')->setParameter('user', $this->riderUser())
            ->andWhere('message.readAt IS NULL')->getQuery()->execute();

        return $this->json([
            'message' => 'Conversation marked as read.',
            'updated' => (int) $updated,
            'read_at' => $now->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function ownedConversation(string $id): Conversation
    {
        $conversation = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('conversation.id = :id')->setParameter('id', $id)
            ->andWhere('orders.rider = :rider')->setParameter('rider', $this->rider())
            ->andWhere('conversation.type = :type')->setParameter('type', ConversationType::CUSTOMER_RIDER->value)
            ->getQuery()->getOneOrNullResult();
        if (!$conversation instanceof Conversation) {
            throw $this->createNotFoundException('Conversation not found.');
        }

        return $conversation;
    }

    /** @return array<string, mixed> */
    private function conversationData(Conversation $conversation): array
    {
        $lastMessage = $this->entityManager->getRepository(Message::class)->findOneBy(
            ['conversation' => $conversation],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
        $unreadCount = (int) $this->entityManager->getRepository(Message::class)->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.conversation = :conversation')->setParameter('conversation', $conversation)
            ->andWhere('message.sender != :user')->setParameter('user', $this->riderUser())
            ->andWhere('message.readAt IS NULL')->getQuery()->getSingleScalarResult();
        $order = $conversation->getOrder();
        $customerUser = $order->getCustomer()->getUser();

        return [
            'id' => $conversation->getId(),
            'type' => $conversation->getType()->value,
            'order' => [
                'id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'restaurant_name' => $order->getRestaurant()->getName(),
            ],
            'counterpart' => ['type' => 'customer', 'id' => $order->getCustomer()->getId(), 'name' => $customerUser->getName()],
            'is_closed' => $conversation->isClosed(),
            'closed_at' => $conversation->getClosedAt()?->format(\DateTimeInterface::ATOM),
            'unread_count' => $unreadCount,
            'last_message' => $lastMessage instanceof Message ? $this->messageData($lastMessage) : null,
            'updated_at' => $conversation->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function messageData(Message $message): array
    {
        return [
            'id' => $message->getId(),
            'conversation_id' => $message->getConversation()->getId(),
            'sender' => ['id' => $message->getSender()->getId(), 'name' => $message->getSender()->getName()],
            'body' => $message->getBody(),
            'read_at' => $message->getReadAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $message->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
