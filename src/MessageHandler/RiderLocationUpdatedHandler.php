<?php

namespace App\MessageHandler;

use App\Message\RiderLocationUpdated;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The rider moved: pages tracking their current order refresh to show the new position. */
#[AsMessageHandler]
final class RiderLocationUpdatedHandler
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public function __invoke(RiderLocationUpdated $message): void
    {
        $this->hub->publish(new Update(
            sprintf('orders/%s/status', $message->orderId),
            '<turbo-stream action="refresh"></turbo-stream>',
            true,
        ));
    }
}
