<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\OrderUpdated;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface as MercureException;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(OrderUpdated $message): void
    {
        $order = $this->entityManager->find(Order::class, $message->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $refresh = '<turbo-stream action="refresh"></turbo-stream>';
        $this->publish(new Update(sprintf('orders/%s/status', $message->orderId), $refresh, true));
        $this->publish(new Update(sprintf('restaurant/%s/orders', $order->getRestaurant()->getId()), $refresh, true));
    }

    /** Live updates are a convenience: a hub that is down must not fail the change that was just saved. */
    private function publish(Update $update): void
    {
        try {
            $this->hub->publish($update);
        } catch (MercureException $exception) {
            $this->logger->warning('Live update not sent: {message}', ['message' => $exception->getMessage(), 'topics' => $update->getTopics()]);
        }
    }
}
