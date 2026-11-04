<?php

namespace App\MessageHandler;

use App\Message\RiderPoolOfferTaken;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiderPoolOfferTakenHandler
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    public function __invoke(RiderPoolOfferTaken $message): void
    {
        $this->hub->publish(new Update(
            'orders/pool',
            json_encode([
                'event' => 'rider_pool.offer_taken',
                'order_id' => $message->orderId,
                'reason' => $message->reason,
                'occurred_at' => $message->occurredAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
            false,
            type: 'rider_pool.offer_taken',
        ));
    }
}
