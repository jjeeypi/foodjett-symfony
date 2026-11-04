<?php

namespace App\MessageHandler;

use App\Message\RiderPoolOfferTaken;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** An order left the rider pool (taken, assigned or cancelled): open pool pages refresh so nobody tries to take it. */
#[AsMessageHandler]
final class RiderPoolOfferTakenHandler
{
    public function __construct(private readonly HubInterface $hub, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(RiderPoolOfferTaken $message): void
    {
        $this->publish(new Update('orders/pool', '<turbo-stream action="refresh"></turbo-stream>', false));
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
