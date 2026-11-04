<?php

namespace App\MessageHandler;

use App\Message\RiderPoolOfferCreated;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An order joined the rider pool or its offer grew: open pool pages refresh. Each rider's
 * page works out their own pickup distance and pay, so the signal is public and empty.
 */
#[AsMessageHandler]
final class RiderPoolOfferCreatedHandler
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public function __invoke(RiderPoolOfferCreated $message): void
    {
        $this->hub->publish(new Update('orders/pool', '<turbo-stream action="refresh"></turbo-stream>', false));
    }
}
