<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\User;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\OrderStatus;
use App\Exception\InvalidOrderTransitionException;
use App\Exception\RiderPoolException;
use App\Security\Voter\ApprovedAccountVoter;
use App\Security\Voter\OrderVoter;
use App\Service\RiderPoolService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider/pool', name: 'api_rider_pool_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderPoolController extends AbstractController
{
    public function __construct(private readonly RiderPoolService $pool)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || null === $user->getRider()) {
            return $this->json(['message' => 'Rider profile not found.'], 404);
        }
        $rider = $user->getRider();
        if (null === $rider->getCurrentLatitude() || null === $rider->getCurrentLongitude()) {
            return $this->json([
                'orders' => [],
                'reason' => 'Set your current location before viewing the rider pool.',
            ]);
        }
        if (RiderAvailabilityStatus::AVAILABLE !== $rider->getAvailabilityStatus()) {
            return $this->json([
                'orders' => [],
                'reason' => 'Go online to view available orders.',
            ]);
        }

        return $this->json(['orders' => $this->pool->visibleOrders($rider)]);
    }

    #[Route('/{id}/accept', name: 'accept', methods: ['POST'])]
    public function accept(Order $order): JsonResponse
    {
        if (OrderStatus::FINDING_RIDER !== $order->getStatus() || null !== $order->getRider()) {
            return $this->json(['message' => 'This order was just taken by another rider.'], 409);
        }
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RIDER, $order);
        $rider = $this->rider();

        try {
            $order = $this->pool->accept($order, $rider);
        } catch (RiderPoolException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (InvalidOrderTransitionException) {
            return $this->json(['message' => 'This order was just taken by another rider.'], 409);
        }

        return $this->json([
            'order' => [
                'id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'status' => $order->getStatus()->value,
                'rider_id' => $order->getRider()?->getId(),
                'rider_assigned_at' => $order->getRiderAssignedAt()?->format(\DateTimeInterface::ATOM),
                'pickup_code' => $order->getPickupCode(),
            ],
        ]);
    }

    #[Route('/{id}/decline', name: 'decline', methods: ['POST'])]
    public function decline(Order $order): JsonResponse
    {
        if (OrderStatus::FINDING_RIDER !== $order->getStatus() || null !== $order->getRider()) {
            return $this->json(['message' => 'This order is no longer available.'], 409);
        }
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RIDER, $order);

        try {
            $decline = $this->pool->decline($order, $this->rider());
        } catch (RiderPoolException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return $this->json([
            'decline' => [
                'order_id' => $decline->getOrder()->getId(),
                'action' => $decline->getAction()->value,
            ],
        ]);
    }

    private function rider(): Rider
    {
        $user = $this->getUser();
        if (!$user instanceof User || null === $user->getRider()) {
            throw $this->createNotFoundException('Rider profile not found.');
        }

        return $user->getRider();
    }
}
