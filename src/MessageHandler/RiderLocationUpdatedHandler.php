<?php

namespace App\MessageHandler;

use App\Message\RiderLocationUpdated;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The rider moved: pages tracking their current order refresh to show the new position. */
#[AsMessageHandler]
final class RiderLocationUpdatedHandler
{
    public function __construct(private readonly HubInterface $hub, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(RiderLocationUpdated $message): void
    {
        $this->publish(new Update(
            sprintf('orders/%s/status', $message->orderId),
            '<turbo-stream action="refresh"></turbo-stream>',
            true,
        ));
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
