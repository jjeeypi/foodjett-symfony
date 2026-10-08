<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\Rider;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Enum\RiderPoolEscalationStage;
use App\Exception\InvalidOrderTransitionException;
use App\Exception\RiderPoolException;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use App\Service\RiderPoolService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/orders', name: 'api_admin_orders_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderController extends AbstractController
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
        private readonly RiderPoolService $riderPool,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/unassigned', name: 'unassigned', methods: ['GET'])]
    public function unassigned(): JsonResponse
    {
        /** @var list<Order> $orders */
        $orders = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->innerJoin('orders.riderPoolOffer', 'offer')
            ->addSelect('offer')
            ->innerJoin('orders.restaurant', 'restaurant')
            ->addSelect('restaurant')
            ->andWhere('orders.status = :status')
            ->andWhere('orders.rider IS NULL')
            ->andWhere('offer.escalationStage IN (:stages)')
            ->setParameter('status', OrderStatus::FINDING_RIDER)
            ->setParameter('stages', [
                RiderPoolEscalationStage::ADMIN_ALERTED->value,
                RiderPoolEscalationStage::CUSTOMER_NOTIFIED->value,
                RiderPoolEscalationStage::AUTO_CANCELLED->value,
            ])
            ->orderBy('orders.riderSearchStartedAt', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
        $now = new \DateTimeImmutable();

        return $this->json([
            'orders' => array_map(static function (Order $order) use ($now): array {
                $startedAt = $order->getRiderSearchStartedAt();
                $seconds = null === $startedAt ? 0 : max(0, $now->getTimestamp() - $startedAt->getTimestamp());

                return [
                    'id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'restaurant_name' => $order->getRestaurant()->getName(),
                    'searching_minutes' => (int) floor($seconds / 60),
                    'rider_search_started_at' => $startedAt?->format(\DateTimeInterface::ATOM),
                    'escalation_stage' => $order->getRiderPoolOffer()?->getEscalationStage()->value,
                    'search_radius_km' => $order->getRiderPoolOffer()?->getSearchRadiusKm(),
                    'incentive_amount' => $order->getRiderPoolOffer()?->getIncentiveAmount(),
                ];
            }, $orders),
        ]);
    }

    #[Route('/{id}/assign-rider', name: 'assign_rider', methods: ['POST'])]
    public function assignRider(Order $order, Request $request): JsonResponse
    {
        if (OrderStatus::FINDING_RIDER !== $order->getStatus() || null !== $order->getRider()) {
            return $this->json(['message' => 'This order was just taken by another rider.'], 409);
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $riderId = $data['rider_id'] ?? null;
        if ((!is_string($riderId) && !is_int($riderId)) || '' === (string) $riderId) {
            return $this->json(['message' => 'rider_id is required.'], 422);
        }
        $rider = $this->entityManager->find(Rider::class, (string) $riderId);
        if (!$rider instanceof Rider) {
            return $this->json(['message' => 'Rider not found.'], 404);
        }

        try {
            $order = $this->riderPool->accept($order, $rider, adminOverride: true);
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
                'admin_assigned' => $order->getRiderPoolOffer()?->isAdminAssigned(),
                'rider_assigned_at' => $order->getRiderAssignedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Order $order, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');
        if ('' === $reason || mb_strlen($reason) > 2000) {
            return $this->json(['message' => 'A cancellation reason of at most 2000 characters is required.'], 422);
        }
        if ($order->getStatus()->isTerminal()) {
            return $this->json(['message' => 'A terminal order cannot be cancelled.'], 409);
        }

        try {
            $order = $this->transitions->transition(
                $order,
                OrderStatus::CANCELLED_BY_ADMIN,
                OrderActor::ADMIN,
                $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason): void {
                    $locked
                        ->setCancellationReason($reason)
                        ->setCancelledBy(OrderActor::ADMIN);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund($locked->getPayment(), PaymentActor::ADMIN, $reason, $now);
                    }
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json([
            'order' => [
                'id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'status' => $order->getStatus()->value,
                'cancellation_reason' => $order->getCancellationReason(),
                'cancelled_by' => $order->getCancelledBy()?->value,
                'payment_status' => $order->getPayment()?->getStatus()->value,
            ],
        ]);
    }
}
