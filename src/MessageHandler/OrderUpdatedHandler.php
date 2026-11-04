<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\OrderUpdated;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OrderUpdatedHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(OrderUpdated $message): void
    {
        $order = $this->entityManager->find(Order::class, $message->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $rider = $order->getRider();

        $this->hub->publish(new Update(
            sprintf('orders/%s/status', $message->orderId),
            json_encode([
                'event' => 'order.updated',
                'kind' => $message->kind,
                'order_id' => $message->orderId,
                'order_number' => $order->getOrderNumber(),
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
            type: 'order.updated',
        ));
    }
}
