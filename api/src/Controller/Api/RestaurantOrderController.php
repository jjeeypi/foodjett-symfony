<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderItemAddon;
use App\Entity\OrderStatusHistory;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Exception\InvalidOrderTransitionException;
use App\Security\Voter\ApprovedAccountVoter;
use App\Security\Voter\OrderVoter;
use App\Service\ApiPaginator;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/orders', name: 'api_restaurant_orders_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantOrderController extends AbstractRestaurantController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
        private readonly ApiPaginator $paginator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $builder = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->orderBy('orders.placedAt', 'DESC');

        $statusValue = $request->query->getString('status');
        if ('' !== $statusValue) {
            $status = OrderStatus::tryFrom($statusValue);
            if (null === $status) {
                return $this->json(['message' => 'Unknown order status filter.'], 422);
            }
            $builder->andWhere('orders.status = :status')->setParameter('status', $status->value);
        }
        if ('' !== ($search = trim($request->query->getString('search')))) {
            $builder->andWhere('LOWER(orders.orderNumber) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $result = $this->paginator->paginate($builder, $request, $this->serializeSummary(...));

        // Keep the Phase 3 response key while adding the standard paginated data/meta shape.
        return $this->json($result + ['orders' => $result['data']]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(string $id): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        return $this->json(['order' => $this->serializeDetail($order)]);
    }

    #[Route('/{id}/accept', name: 'accept', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function accept(string $id, Request $request): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $minutes = filter_var($data['estimated_prep_minutes'] ?? null, FILTER_VALIDATE_INT);
        if (false === $minutes || $minutes < 1 || $minutes > 180) {
            return $this->json(['message' => 'estimated_prep_minutes must be between 1 and 180.'], 422);
        }

        try {
            $order = $this->transitions->transitionSequence(
                $order,
                [OrderStatus::ACCEPTED, OrderStatus::PREPARING, OrderStatus::FINDING_RIDER],
                OrderActor::RESTAURANT,
                ['Order accepted', 'Food preparation started', 'Rider search started in parallel with preparation'],
                static function (Order $locked, \DateTimeImmutable $now) use ($minutes): void {
                    $locked->setAcceptedAt($now)->setEstimatedPrepMinutes($minutes)
                        ->setEstimatedReadyAt($now->modify(sprintf('+%d minutes', $minutes)));
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json(['order' => $this->serializeSummary($order)]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');
        if ('' === $reason || mb_strlen($reason) > 2000) {
            return $this->json(['message' => 'A rejection reason of at most 2000 characters is required.'], 422);
        }

        try {
            $order = $this->transitions->transition(
                $order,
                OrderStatus::REJECTED_BY_RESTAURANT,
                OrderActor::RESTAURANT,
                $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason): void {
                    $locked->setRejectionReason($reason);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund($locked->getPayment(), PaymentActor::SYSTEM, 'Restaurant rejected order: '.$reason, $now);
                    }
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json(['order' => $this->serializeSummary($order)]);
    }

    #[Route('/{id}/extend-prep-time', name: 'extend_prep_time', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function extendPrepTime(string $id, Request $request): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $minutes = filter_var($data['minutes'] ?? null, FILTER_VALIDATE_INT);
        if (false === $minutes || $minutes < 1 || $minutes > 120) {
            return $this->json(['message' => 'minutes must be between 1 and 120.'], 422);
        }
        if (null === $order->getEstimatedReadyAt() || $order->getStatus()->isTerminal()) {
            return $this->json(['message' => 'Preparation time cannot be extended for this order.'], 409);
        }

        $order = $this->transitions->recordUpdate(
            $order,
            OrderActor::RESTAURANT,
            'prep_time_extended',
            sprintf('Preparation time extended by %d minutes.', $minutes),
            static function (Order $locked) use ($minutes): void {
                $locked->setPrepExtendedMinutes(($locked->getPrepExtendedMinutes() ?? 0) + $minutes)
                    ->setEstimatedReadyAt($locked->getEstimatedReadyAt()?->modify(sprintf('+%d minutes', $minutes)));
            },
        );

        return $this->json(['order' => $this->serializeSummary($order)]);
    }

    #[Route('/{id}/mark-ready', name: 'mark_ready', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function markReady(string $id): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        if (null !== $order->getReadyAt() || $order->getStatus()->isTerminal()) {
            return $this->json(['message' => 'This order cannot be marked ready.'], 409);
        }
        if (!in_array($order->getStatus(), [OrderStatus::FINDING_RIDER, OrderStatus::RIDER_ASSIGNED, OrderStatus::AT_RESTAURANT], true)) {
            return $this->json(['message' => 'Food can only be marked ready after restaurant acceptance.'], 409);
        }

        $order = $this->transitions->recordUpdate(
            $order,
            OrderActor::RESTAURANT,
            'food_ready',
            'Restaurant marked the food ready for pickup.',
            static function (Order $locked, \DateTimeImmutable $now): void {
                $locked->setReadyAt($now);
            },
            OrderStatus::READY,
        );

        return $this->json(['order' => $this->serializeSummary($order)]);
    }

    private function ownedOrder(string $id): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->andWhere('orders.id = :id')->setParameter('id', $id)
            ->andWhere('orders.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Order not found.');
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function serializeSummary(Order $order): array
    {
        return [
            'id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $order->getStatus()->value,
            'customer_name' => $order->getCustomer()->getUser()->getName(), 'total_amount' => $order->getTotalAmount(),
            'payment_method' => $order->getPaymentMethod()->value, 'estimated_prep_minutes' => $order->getEstimatedPrepMinutes(),
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
            'ready_at' => $order->getReadyAt()?->format(\DateTimeInterface::ATOM),
            'rider_search_started_at' => $order->getRiderSearchStartedAt()?->format(\DateTimeInterface::ATOM),
            'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(Order $order): array
    {
        $address = $order->getCustomerAddress();
        $payment = $order->getPayment();
        $history = $order->getStatusHistory()->toArray();
        usort($history, static fn (OrderStatusHistory $a, OrderStatusHistory $b): int => $a->getCreatedAt() <=> $b->getCreatedAt());

        return $this->serializeSummary($order) + [
            'subtotal' => $order->getSubtotal(), 'delivery_fee' => $order->getDeliveryFee(), 'service_fee' => $order->getServiceFee(),
            'discount_amount' => $order->getDiscountAmount(), 'tip_amount' => $order->getTipAmount(),
            'commission_amount' => $order->getCommissionAmount(), 'customer_notes' => $order->getCustomerNotes(),
            'rejection_reason' => $order->getRejectionReason(), 'cancellation_reason' => $order->getCancellationReason(),
            'rider' => null === $order->getRider() ? null : ['id' => $order->getRider()?->getId(), 'name' => $order->getRider()?->getUser()->getName()],
            'delivery_address' => [
                'id' => $address->getId(), 'label' => $address->getLabel(), 'address_line' => $address->getAddressLine(),
                'landmark' => $address->getLandmark(), 'delivery_instructions' => $address->getDeliveryInstructions(),
                'latitude' => $address->getLatitude(), 'longitude' => $address->getLongitude(),
            ],
            'items' => array_map($this->serializeItem(...), $order->getItems()->toArray()),
            'status_history' => array_map(static fn (OrderStatusHistory $entry): array => [
                'id' => $entry->getId(), 'status' => $entry->getStatus(), 'changed_by' => $entry->getChangedBy()->value,
                'note' => $entry->getNote(), 'created_at' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $history),
            'payment' => null === $payment ? null : [
                'id' => $payment->getId(), 'method' => $payment->getMethod()->value, 'status' => $payment->getStatus()->value,
                'amount' => $payment->getAmount(), 'refunded_amount' => $payment->getRefundedAmount(),
                'transaction_reference' => $payment->getTransactionReference(), 'paid_at' => $payment->getPaidAt()?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeItem(OrderItem $item): array
    {
        return [
            'id' => $item->getId(), 'menu_item_id' => $item->getMenuItem()->getId(), 'name' => $item->getMenuItem()->getName(),
            'variant' => null === $item->getMenuItemVariant() ? null : ['id' => $item->getMenuItemVariant()?->getId(), 'name' => $item->getMenuItemVariant()?->getName()],
            'quantity' => $item->getQuantity(), 'unit_price' => $item->getUnitPrice(), 'special_instructions' => $item->getSpecialInstructions(),
            'addons' => array_map(static fn (OrderItemAddon $addon): array => [
                'id' => $addon->getId(), 'menu_item_addon_id' => $addon->getMenuItemAddon()->getId(),
                'name' => $addon->getMenuItemAddon()->getName(), 'price' => $addon->getPrice(),
            ], $item->getAddons()->toArray()),
        ];
    }
}
