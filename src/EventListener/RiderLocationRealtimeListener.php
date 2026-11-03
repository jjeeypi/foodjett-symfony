<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\RiderLocationUpdatedEvent;
use App\Service\MercurePublisher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class RiderLocationRealtimeListener
{
    public function __construct(private MercurePublisher $publisher)
    {
    }

    #[AsEventListener]
    public function __invoke(RiderLocationUpdatedEvent $event): void
    {
        $this->publisher->publish(sprintf('orders/%s/status', $event->orderId), [
            'event' => 'rider.location_updated',
            'order_id' => $event->orderId,
            'rider_id' => $event->riderId,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'timestamp' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ], type: 'rider.location_updated');
    }
}
