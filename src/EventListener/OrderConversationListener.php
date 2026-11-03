<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Message;
use App\Entity\Order;
use App\Enum\ConversationType;
use App\Enum\OrderStatus;
use App\Event\MessageSentEvent;
use App\Event\OrderStatusChangedEvent;
use App\Service\ConversationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class OrderConversationListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ConversationService $conversations,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[AsEventListener(priority: 100)]
    public function __invoke(OrderStatusChangedEvent $event): void
    {
        $type = match ($event->currentStatus) {
            OrderStatus::ACCEPTED => ConversationType::CUSTOMER_RESTAURANT,
            OrderStatus::RIDER_ASSIGNED => ConversationType::CUSTOMER_RIDER,
            default => null,
        };
        if (null === $type) {
            return;
        }

        $order = $this->entityManager->find(Order::class, $event->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $conversation = $this->conversations->forOrder($order, $type, $event->changedAt);
        $message = null;

        if (ConversationType::CUSTOMER_RESTAURANT === $type && $conversation->getMessages()->isEmpty()) {
            $restaurant = $order->getRestaurant();
            $message = (new Message())
                ->setConversation($conversation)
                ->setSender($restaurant->getUser())
                ->setBody(sprintf(
                    'Thank you for ordering from %s! We\'ve received order %s and are getting it ready. We\'ll keep you updated here.',
                    $restaurant->getName(),
                    $order->getOrderNumber(),
                ))
                ->setCreatedAt($event->changedAt)
                ->setUpdatedAt($event->changedAt);
            $conversation->addMessage($message)->setUpdatedAt($event->changedAt);
            $this->entityManager->persist($message);
        }

        $this->entityManager->flush();

        if ($message instanceof Message) {
            $this->eventDispatcher->dispatch(new MessageSentEvent(
                messageId: (string) $message->getId(),
                conversationId: (string) $conversation->getId(),
                senderUserId: (string) $order->getRestaurant()->getUser()->getId(),
                recipientUserId: (string) $order->getCustomer()->getUser()->getId(),
                sentAt: $event->changedAt,
            ));
        }
    }
}
