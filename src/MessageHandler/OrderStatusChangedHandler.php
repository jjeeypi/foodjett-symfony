<?php

namespace App\MessageHandler;

use App\Entity\Message;
use App\Entity\Order;
use App\Enum\ConversationType;
use App\Enum\OrderStatus;
use App\Message\ConversationMessageSent;
use App\Message\OrderStatusChanged;
use App\Service\ConversationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Opens the order conversation the new status calls for, then publishes the
 * status change (and, for a new order, the restaurant notice) to Mercure.
 */
#[AsMessageHandler]
final class OrderStatusChangedHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationService $conversations,
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
            OrderStatus::ACCEPTED => ConversationType::CUSTOMER_RESTAURANT,
            OrderStatus::RIDER_ASSIGNED => ConversationType::CUSTOMER_RIDER,
            default => null,
        };
        if (null === $type) {
            return;
        }

        $conversation = $this->conversations->forOrder($order, $type, $message->changedAt);
        $greeting = null;

        if (ConversationType::CUSTOMER_RESTAURANT === $type && $conversation->getMessages()->isEmpty()) {
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

    private function publishStatus(Order $order, OrderStatusChanged $message): void
    {
        $rider = $order->getRider();

        $this->hub->publish(new Update(
            sprintf('orders/%s/status', $message->orderId),
            json_encode([
                'event' => 'order.status_changed',
                'order_id' => $message->orderId,
                'order_number' => $order->getOrderNumber(),
                'previous_status' => $message->previousStatus?->value,
                'status' => $message->currentStatus->value,
                'changed_by' => $message->changedBy->value,
                'note' => $message->note,
                'changed_at' => $message->changedAt->format(\DateTimeInterface::ATOM),
                'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
                'rider' => null === $rider ? null : [
                    'id' => (string) $rider->getId(),
                    'name' => $rider->getUser()->getName(),
                    'vehicle_type' => $rider->getVehicleType()->value,
                ],
            ], JSON_THROW_ON_ERROR),
            true,
            type: 'order.status_changed',
        ));

        if (null !== $message->previousStatus) {
            return;
        }

        $this->hub->publish(new Update(
            sprintf('restaurant/%s/orders', $order->getRestaurant()->getId()),
            json_encode([
                'event' => 'order.placed',
                'order_id' => $message->orderId,
                'order_number' => $order->getOrderNumber(),
                'customer_name' => $order->getCustomer()->getUser()->getName(),
                'total_amount' => $order->getTotalAmount(),
                'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
            true,
            type: 'order.placed',
        ));
    }
}
