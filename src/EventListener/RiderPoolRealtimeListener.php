<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Order;
use App\Entity\RiderPoolOffer;
use App\Event\RiderPoolOfferCreatedEvent;
use App\Event\RiderPoolOfferTakenEvent;
use App\Service\DeliveryZoneService;
use App\Service\MercurePublisher;
use App\Service\RiderPayCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class RiderPoolRealtimeListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RiderPayCalculator $payCalculator,
        private MercurePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function onOfferCreated(RiderPoolOfferCreatedEvent $event): void
    {
        $order = $this->entityManager->find(Order::class, $event->orderId);
        $offer = $order?->getRiderPoolOffer();
        if (!$order instanceof Order || !$offer instanceof RiderPoolOffer) {
            return;
        }

        $deliveryDistance = DeliveryZoneService::distanceKm(
            (float) $order->getRestaurant()->getLatitude(),
            (float) $order->getRestaurant()->getLongitude(),
            (float) $order->getCustomerAddress()->getLatitude(),
            (float) $order->getCustomerAddress()->getLongitude(),
        );
        // Pickup distance varies per listening rider. This is the minimum offer value;
        // clients add their own pickup-distance component when rendering the pool.
        $pay = $this->payCalculator->estimate(0.0, $deliveryDistance, $offer);

        $this->publisher->publish('orders/pool', [
            'event' => 'rider_pool.offer_created',
            'order_id' => $event->orderId,
            'restaurant' => [
                'name' => $order->getRestaurant()->getName(),
                'latitude' => $order->getRestaurant()->getLatitude(),
                'longitude' => $order->getRestaurant()->getLongitude(),
            ],
            'estimated_pay' => $pay['total'],
            'pay_breakdown' => $pay,
            'delivery_distance_km' => number_format(round($deliveryDistance, 2), 2, '.', ''),
            'search_radius_km' => $offer->getSearchRadiusKm(),
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ], private: false, type: 'rider_pool.offer_created');
    }

    #[AsEventListener]
    public function onOfferTaken(RiderPoolOfferTakenEvent $event): void
    {
        $this->publisher->publish('orders/pool', [
            'event' => 'rider_pool.offer_taken',
            'order_id' => $event->orderId,
            'reason' => $event->reason,
            'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
        ], private: false, type: 'rider_pool.offer_taken');
    }
}
