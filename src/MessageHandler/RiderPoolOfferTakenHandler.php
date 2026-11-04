<?php

namespace App\MessageHandler;

use App\Message\RiderPoolOfferTaken;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** An order left the rider pool (taken, assigned or cancelled): open pool pages refresh so nobody tries to take it. */
#[AsMessageHandler]
final class RiderPoolOfferTakenHandler
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public function __invoke(RiderPoolOfferTaken $message): void
    {
        $this->hub->publish(new Update('orders/pool', '<turbo-stream action="refresh"></turbo-stream>', false));
    }
}
