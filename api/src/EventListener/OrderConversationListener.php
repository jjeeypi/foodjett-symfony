<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Order;
use App\Enum\ConversationType;
use App\Enum\OrderStatus;
use App\Event\OrderStatusChangedEvent;
use App\Service\ConversationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class OrderConversationListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ConversationService $conversations,
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

        $this->conversations->forOrder($order, $type, $event->changedAt);
        $this->entityManager->flush();
    }
}
