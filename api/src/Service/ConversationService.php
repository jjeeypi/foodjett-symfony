<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Order;
use App\Enum\ConversationType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConversationService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function forOrder(
        Order $order,
        ConversationType $type,
        ?\DateTimeImmutable $now = null,
    ): Conversation {
        if (ConversationType::CUSTOMER_RIDER === $type && null === $order->getRider()) {
            throw new \DomainException('A rider must be assigned before opening this conversation.');
        }

        foreach ($order->getConversations() as $conversation) {
            if ($conversation->getType() === $type) {
                return $conversation;
            }
        }

        $existing = $this->entityManager->getRepository(Conversation::class)->findOneBy([
            'order' => $order,
            'type' => $type,
        ]);
        if ($existing instanceof Conversation) {
            return $existing;
        }

        $now ??= new \DateTimeImmutable();
        $conversation = (new Conversation())
            ->setOrder($order)
            ->setType($type)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->addConversation($conversation);
        $this->entityManager->persist($conversation);

        return $conversation;
    }
}
