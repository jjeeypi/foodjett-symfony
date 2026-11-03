<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderItemAddon;
use App\Entity\Rider;
use App\Entity\User;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Enum\RiderPoolEscalationStage;
use App\Exception\InvalidOrderTransitionException;
use App\Exception\RiderPoolException;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use App\Service\RiderPoolService;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
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
        private readonly ApiPaginator $paginator,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->innerJoin('orders.customer', 'customer')->addSelect('customer')
            ->innerJoin('customer.user', 'customerUser')->addSelect('customerUser')
            ->leftJoin('orders.rider', 'rider')->addSelect('rider')
            ->leftJoin('rider.user', 'riderUser')->addSelect('riderUser')
            ->orderBy('orders.placedAt', 'DESC');
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = OrderStatus::tryFrom($statusValue);
            if (null === $status) {
                return $this->json(['message' => 'Invalid status.'], 422);
            }
            $query->andWhere('orders.status = :status')->setParameter('status', $status->value);
        }
        if ('' !== ($restaurantId = trim((string) $request->query->get('restaurant_id', '')))) {
            $query->andWhere('restaurant.id = :restaurantId')->setParameter('restaurantId', $restaurantId);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $parameter => $operator) {
            if ('' === ($value = trim((string) $request->query->get($parameter, '')))) {
                continue;
            }
            try {
                $date = new \DateTimeImmutable($value.('date_to' === $parameter && 10 === strlen($value) ? ' 23:59:59' : ''));
            } catch (\Throwable) {
                return $this->json(['message' => $parameter.' must be a valid date.'], 422);
            }
            $query->andWhere('orders.placedAt '.$operator.' :'.$parameter)->setParameter($parameter, $date);
        }

        return $this->json($this->paginator->paginate($query, $request, fn (Order $order): array => $this->summary($order)));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], priority: -10)]
    public function show(Order $order): JsonResponse
    {
        return $this->json(['order' => $this->detail($order)]);
    }

    #[Route('/unassigned', name: 'unassigned', methods: ['GET'])]
    public function unassigned(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
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
            ->orderBy('orders.riderSearchStartedAt', 'ASC');
        $now = new \DateTimeImmutable();
        $page = $this->paginator->paginate($query, $request, static function (Order $order) use ($now): array {
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
            });

        // Keep the Phase 4 `orders` key while also exposing the standard paginated shape.
        return $this->json(['orders' => $page['data']] + $page);
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

        $this->audit->record($this->admin(), 'order.rider_assigned', 'order', (string) $order->getId(), [
            'rider_id' => $rider->getId(),
            'admin_assigned' => true,
        ]);
        $this->entityManager->flush();

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
                    $this->audit->record($this->admin(), 'order.cancelled', 'order', (string) $locked->getId(), [
                        'status' => ['from' => $locked->getStatus()->value, 'to' => OrderStatus::CANCELLED_BY_ADMIN->value],
                        'reason' => $reason,
                    ]);
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

    /** @return array<string, mixed> */
    private function summary(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'restaurant' => ['id' => $order->getRestaurant()->getId(), 'name' => $order->getRestaurant()->getName()],
            'customer' => ['id' => $order->getCustomer()->getId(), 'name' => $order->getCustomer()->getUser()->getName()],
            'rider' => null === $order->getRider() ? null : ['id' => $order->getRider()?->getId(), 'name' => $order->getRider()?->getUser()->getName()],
            'status' => $order->getStatus()->value,
            'total_amount' => $order->getTotalAmount(),
            'payment_method' => $order->getPaymentMethod()->value,
            'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Order $order): array
    {
        $data = $this->summary($order) + [
            'subtotal' => $order->getSubtotal(),
            'delivery_fee' => $order->getDeliveryFee(),
            'service_fee' => $order->getServiceFee(),
            'discount_amount' => $order->getDiscountAmount(),
            'tip_amount' => $order->getTipAmount(),
            'commission_amount' => $order->getCommissionAmount(),
            'customer_notes' => $order->getCustomerNotes(),
            'rejection_reason' => $order->getRejectionReason(),
            'cancellation_reason' => $order->getCancellationReason(),
            'cancelled_by' => $order->getCancelledBy()?->value,
            'accepted_at' => $order->getAcceptedAt()?->format(\DateTimeInterface::ATOM),
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
            'ready_at' => $order->getReadyAt()?->format(\DateTimeInterface::ATOM),
            'rider_search_started_at' => $order->getRiderSearchStartedAt()?->format(\DateTimeInterface::ATOM),
            'rider_assigned_at' => $order->getRiderAssignedAt()?->format(\DateTimeInterface::ATOM),
            'picked_up_at' => $order->getPickedUpAt()?->format(\DateTimeInterface::ATOM),
            'delivered_at' => $order->getDeliveredAt()?->format(\DateTimeInterface::ATOM),
            'delivery_address' => [
                'id' => $order->getCustomerAddress()->getId(),
                'label' => $order->getCustomerAddress()->getLabel(),
                'address_line' => $order->getCustomerAddress()->getAddressLine(),
                'landmark' => $order->getCustomerAddress()->getLandmark(),
                'delivery_instructions' => $order->getCustomerAddress()->getDeliveryInstructions(),
                'latitude' => $order->getCustomerAddress()->getLatitude(),
                'longitude' => $order->getCustomerAddress()->getLongitude(),
            ],
        ];
        $data['items'] = array_map(static fn (OrderItem $item): array => [
            'id' => $item->getId(),
            'menu_item_id' => $item->getMenuItem()->getId(),
            'name' => $item->getMenuItem()->getName(),
            'variant' => null === $item->getMenuItemVariant() ? null : [
                'id' => $item->getMenuItemVariant()?->getId(),
                'name' => $item->getMenuItemVariant()?->getName(),
            ],
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPrice(),
            'special_instructions' => $item->getSpecialInstructions(),
            'addons' => array_map(static fn (OrderItemAddon $addon): array => [
                'id' => $addon->getId(),
                'name' => $addon->getMenuItemAddon()->getName(),
                'price' => $addon->getPrice(),
            ], $item->getAddons()->toArray()),
        ], $order->getItems()->toArray());
        $data['status_history'] = array_map(static fn ($history): array => [
            'id' => $history->getId(),
            'status' => $history->getStatus(),
            'changed_by' => $history->getChangedBy()->value,
            'note' => $history->getNote(),
            'created_at' => $history->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $order->getStatusHistory()->toArray());
        $payment = $order->getPayment();
        $data['payment'] = null === $payment ? null : [
            'id' => $payment->getId(),
            'method' => $payment->getMethod()->value,
            'status' => $payment->getStatus()->value,
            'amount' => $payment->getAmount(),
            'refunded_amount' => $payment->getRefundedAmount(),
            'transaction_reference' => $payment->getTransactionReference(),
            'paid_at' => $payment->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'refunded_at' => $payment->getRefundedAt()?->format(\DateTimeInterface::ATOM),
        ];
        $offer = $order->getRiderPoolOffer();
        $data['rider_pool_offer'] = null === $offer ? null : [
            'id' => $offer->getId(),
            'search_radius_km' => $offer->getSearchRadiusKm(),
            'incentive_amount' => $offer->getIncentiveAmount(),
            'escalation_stage' => $offer->getEscalationStage()->value,
            'admin_assigned' => $offer->isAdminAssigned(),
        ];

        return $data;
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
