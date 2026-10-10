<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Event\RiderLocationUpdatedEvent;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/api/rider/location', name: 'api_rider_location_update', methods: ['POST'])]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderLocationController extends AbstractRiderController
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct($entityManager);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $order = $this->activeOrder(false);
        if (null === $order) {
            return $this->json(['message' => 'Location updates require an active delivery.'], 409);
        }
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        [$latitude, $longitude, $errors] = $this->coordinates($data, true);
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }
        $rider = $this->rider();
        $now = new \DateTimeImmutable();
        if (null !== $rider->getLastLocationAt() && $rider->getLastLocationAt() > $now->modify('-5 seconds')) {
            return $this->json(['updated' => false, 'throttled' => true, 'last_location_at' => $rider->getLastLocationAt()->format(\DateTimeInterface::ATOM)]);
        }
        $rider->setCurrentLatitude($latitude)->setCurrentLongitude($longitude)->setLastLocationAt($now)->setUpdatedAt($now);
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new RiderLocationUpdatedEvent(
            orderId: (string) $order->getId(),
            riderId: (string) $rider->getId(),
            latitude: (string) $latitude,
            longitude: (string) $longitude,
            occurredAt: $now,
        ));

        return $this->json(['updated' => true, 'throttled' => false, 'order_id' => $order->getId(), 'latitude' => $latitude, 'longitude' => $longitude, 'last_location_at' => $now->format(\DateTimeInterface::ATOM)]);
    }
}
