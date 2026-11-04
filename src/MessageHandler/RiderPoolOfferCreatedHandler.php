<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Entity\RiderPoolOffer;
use App\Message\RiderPoolOfferCreated;
use App\Repository\DeliveryZoneRepository;
use App\Service\OrderTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiderPoolOfferCreatedHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderTransitionService $transitions,
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(RiderPoolOfferCreated $message): void
    {
        $order = $this->entityManager->find(Order::class, $message->orderId);
        $offer = $order?->getRiderPoolOffer();
        if (!$order instanceof Order || !$offer instanceof RiderPoolOffer) {
            return;
        }

        $deliveryDistance = DeliveryZoneRepository::distanceKm(
            (float) $order->getRestaurant()->getLatitude(),
            (float) $order->getRestaurant()->getLongitude(),
            (float) $order->getCustomerAddress()->getLatitude(),
            (float) $order->getCustomerAddress()->getLongitude(),
        );
        // Pickup distance varies per listening rider. This is the minimum offer value;
        // clients add their own pickup-distance component when rendering the pool.
        $pay = $this->transitions->estimateRiderPay(0.0, $deliveryDistance, $offer);

        $this->hub->publish(new Update(
            'orders/pool',
            json_encode([
                'event' => 'rider_pool.offer_created',
                'order_id' => $message->orderId,
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
                'occurred_at' => $message->occurredAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
            false,
            type: 'rider_pool.offer_created',
        ));
    }
}
