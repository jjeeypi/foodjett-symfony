<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Order;
use App\Event\OrderStatusChangedEvent;
use App\Event\OrderUpdatedEvent;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class OrderRealtimeListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MercurePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function onStatusChanged(OrderStatusChangedEvent $event): void
    {
        $order = $this->entityManager->find(Order::class, $event->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $this->publisher->publish(
            sprintf('orders/%s/status', $event->orderId),
            [
                'event' => 'order.status_changed',
                'order_id' => $event->orderId,
                'order_number' => $order->getOrderNumber(),
                'previous_status' => $event->previousStatus?->value,
                'status' => $event->currentStatus->value,
                'changed_by' => $event->changedBy->value,
                'note' => $event->note,
                'changed_at' => $event->changedAt->format(\DateTimeInterface::ATOM),
                ...$this->context($order),
            ],
            type: 'order.status_changed',
        );

        if (null === $event->previousStatus) {
            $this->publisher->publish(
                sprintf('restaurant/%s/orders', $order->getRestaurant()->getId()),
                [
                    'event' => 'order.placed',
                    'order_id' => $event->orderId,
                    'order_number' => $order->getOrderNumber(),
                    'customer_name' => $order->getCustomer()->getUser()->getName(),
                    'total_amount' => $order->getTotalAmount(),
                    'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
                ],
                type: 'order.placed',
            );
        }
    }

    #[AsEventListener]
    public function onOrderUpdated(OrderUpdatedEvent $event): void
    {
        $order = $this->entityManager->find(Order::class, $event->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $this->publisher->publish(
            sprintf('orders/%s/status', $event->orderId),
            [
                'event' => 'order.updated',
                'kind' => $event->kind,
                'order_id' => $event->orderId,
                'order_number' => $order->getOrderNumber(),
                'status' => $event->currentStatus->value,
                'changed_by' => $event->changedBy->value,
                'note' => $event->note,
                'changed_at' => $event->changedAt->format(\DateTimeInterface::ATOM),
                ...$this->context($order),
            ],
            type: 'order.updated',
        );
    }

    /** @return array{estimated_ready_at: ?string, rider: ?array{id: string, name: string, vehicle_type: string}} */
    private function context(Order $order): array
    {
        $rider = $order->getRider();

        return [
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
            'rider' => null === $rider ? null : [
                'id' => (string) $rider->getId(),
                'name' => $rider->getUser()->getName(),
                'vehicle_type' => $rider->getVehicleType()->value,
            ],
        ];
    }
}
