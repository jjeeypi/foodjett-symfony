<?php

namespace App\MessageHandler;

use App\Message\RiderLocationUpdated;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
            json_encode([
                'event' => 'rider.location_updated',
                'order_id' => $message->orderId,
                'rider_id' => $message->riderId,
                'latitude' => $message->latitude,
                'longitude' => $message->longitude,
                'timestamp' => $message->occurredAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
            true,
            type: 'rider.location_updated',
        ));
    }
}
