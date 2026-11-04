<?php

namespace App\MessageHandler;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Order;
use App\Message\ConversationMessageSent;
use App\Message\OrderStatusChanged;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Opens the order conversation the new status calls for, then tells the pages
 * following the order and the restaurant's queue to refresh.
 */
#[AsMessageHandler]
final class OrderStatusChangedHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationRepository $conversations,
        private readonly MessageBusInterface $bus,
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(OrderStatusChanged $message): void
    {
        $order = $this->entityManager->find(Order::class, $message->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $this->openConversation($order, $message);
        $this->publishStatus($order, $message);
    }

    private function openConversation(Order $order, OrderStatusChanged $message): void
    {
        $type = match ($message->currentStatus) {
            Order::STATUS_ACCEPTED => Conversation::TYPE_CUSTOMER_RESTAURANT,
            Order::STATUS_RIDER_ASSIGNED => Conversation::TYPE_CUSTOMER_RIDER,
            default => null,
        };
        if (null === $type) {
            return;
        }

        $conversation = $this->conversations->forOrder($order, $type, $message->changedAt);
        $greeting = null;

        if (Conversation::TYPE_CUSTOMER_RESTAURANT === $type && $conversation->getMessages()->isEmpty()) {
            $restaurant = $order->getRestaurant();
            $greeting = (new Message())
                ->setConversation($conversation)
                ->setSender($restaurant->getUser())
                ->setBody(sprintf(
                    'Thank you for ordering from %s! We\'ve received order %s and are getting it ready. We\'ll keep you updated here.',
                    $restaurant->getName(),
                    $order->getOrderNumber(),
                ))
                ->setCreatedAt($message->changedAt)
                ->setUpdatedAt($message->changedAt);
            $conversation->addMessage($greeting)->setUpdatedAt($message->changedAt);
            $this->entityManager->persist($greeting);
        }

        $this->entityManager->flush();

        if ($greeting instanceof Message) {
            $this->bus->dispatch(new ConversationMessageSent(
                messageId: (string) $greeting->getId(),
                conversationId: (string) $conversation->getId(),
                senderUserId: (string) $order->getRestaurant()->getUser()->getId(),
                recipientUserId: (string) $order->getCustomer()->getUser()->getId(),
                sentAt: $message->changedAt,
            ));
        }
    }

    /** Pages showing the order, and the restaurant's queue (which also learns of new orders this way), refresh. */
    private function publishStatus(Order $order, OrderStatusChanged $message): void
    {
        $refresh = '<turbo-stream action="refresh"></turbo-stream>';
        $this->hub->publish(new Update(sprintf('orders/%s/status', $message->orderId), $refresh, true));
        $this->hub->publish(new Update(sprintf('restaurant/%s/orders', $order->getRestaurant()->getId()), $refresh, true));
    }
}
