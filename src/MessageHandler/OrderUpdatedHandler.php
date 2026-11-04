<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\OrderUpdated;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An order changed without changing status (more prep time, food ready): the pages
 * showing it, and the restaurant's queue, refresh.
 */
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

        $refresh = '<turbo-stream action="refresh"></turbo-stream>';
        $this->hub->publish(new Update(sprintf('orders/%s/status', $message->orderId), $refresh, true));
        $this->hub->publish(new Update(sprintf('restaurant/%s/orders', $order->getRestaurant()->getId()), $refresh, true));
    }
}
