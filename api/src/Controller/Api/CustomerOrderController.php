<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderItemAddon;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\RestaurantReview;
use App\Entity\RiderReview;
use App\Enum\OrderActor;
use App\Enum\OrderReportAgainst;
use App\Enum\OrderReportStatus;
use App\Enum\OrderReportType;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Exception\InvalidOrderTransitionException;
use App\Security\Voter\OrderVoter;
use App\Service\ApiPaginator;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer/orders', name: 'api_customer_orders_')]
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerOrderController extends AbstractCustomerController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $this->customer())
            ->orderBy('orders.placedAt', 'DESC');
        $filter = $request->query->getString('filter');
        $terminal = array_map(static fn (OrderStatus $status): string => $status->value, array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isTerminal()));
        if ('active' === $filter) {
            $query->andWhere('orders.status NOT IN (:terminal)')->setParameter('terminal', $terminal);
        } elseif ('past' === $filter) {
            $query->andWhere('orders.status IN (:terminal)')->setParameter('terminal', $terminal);
        } elseif ('' !== $filter) {
            return $this->json(['message' => 'filter must be active or past.'], 422);
        }

        return $this->json($this->paginator->paginate($query, $request, $this->summary(...)));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(string $id): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        return $this->json(['order' => $this->detail($order)]);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order, 'This order can no longer be cancelled by the customer.');
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : 'Cancelled by customer.');
        if ('' === $reason || mb_strlen($reason) > 2000) {
            return $this->json(['message' => 'reason must be between 1 and 2000 characters.'], 422);
        }

        try {
            $order = $this->transitions->transition(
                $order,
                OrderStatus::CANCELLED_BY_CUSTOMER,
                OrderActor::CUSTOMER,
                $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason): void {
                    $locked->setCancellationReason($reason)->setCancelledBy(OrderActor::CUSTOMER);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund($locked->getPayment(), PaymentActor::CUSTOMER, $reason, $now);
                    }
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json(['order' => $this->summary($order) + [
            'cancellation_reason' => $order->getCancellationReason(), 'cancelled_by' => $order->getCancelledBy()?->value,
            'payment_status' => $order->getPayment()?->getStatus()->value,
        ]]);
    }

    #[Route('/{id}/reorder', name: 'reorder', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reorder(string $id): JsonResponse
    {
        $order = $this->ownedOrder($id);
        if (!$order->getStatus()->isTerminal()) {
            return $this->json(['message' => 'Only a past order can be reordered.'], 409);
        }
        $unavailable = [];
        $items = [];
        foreach ($order->getItems() as $orderItem) {
            $menuItem = $orderItem->getMenuItem();
            $variant = $orderItem->getMenuItemVariant();
            $addons = [];
            $unitPrice = (float) $menuItem->getBasePrice() + (null === $variant ? 0.0 : (float) $variant->getPriceDelta());
            if (!$menuItem->isAvailable()) {
                $unavailable[] = ['menu_item_id' => $menuItem->getId(), 'name' => $menuItem->getName()];
                continue;
            }
            foreach ($orderItem->getAddons() as $snapshot) {
                $addon = $snapshot->getMenuItemAddon();
                if (!$addon->isAvailable()) {
                    $unavailable[] = ['menu_item_id' => $menuItem->getId(), 'name' => $menuItem->getName().' / '.$addon->getName()];
                    continue 2;
                }
                $unitPrice += (float) $addon->getPrice();
                $addons[] = ['id' => $addon->getId(), 'name' => $addon->getName(), 'price' => $addon->getPrice()];
            }
            $items[] = [
                'menu_item_id' => $menuItem->getId(), 'name' => $menuItem->getName(), 'base_price' => $menuItem->getBasePrice(),
                'variant' => null === $variant ? null : ['id' => $variant->getId(), 'name' => $variant->getName(), 'price_delta' => $variant->getPriceDelta()],
                'addons' => $addons, 'quantity' => $orderItem->getQuantity(), 'special_instructions' => $orderItem->getSpecialInstructions(),
                'current_unit_price' => number_format($unitPrice, 2, '.', ''),
            ];
        }
        if ([] !== $unavailable) {
            return $this->json(['message' => 'Some items from this order are no longer available.', 'unavailable_items' => $unavailable], 409);
        }

        return $this->json(['cart' => ['restaurant_id' => $order->getRestaurant()->getId(), 'restaurant_name' => $order->getRestaurant()->getName(), 'items' => $items]]);
    }

    #[Route('/{id}/reviews', name: 'reviews', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reviews(string $id, Request $request): JsonResponse
    {
        $order = $this->ownedOrder($id);
        if (OrderStatus::DELIVERED !== $order->getStatus() || null === $order->getDeliveredAt()) {
            return $this->json(['message' => 'Only delivered orders can be reviewed.'], 409);
        }
        if (new \DateTimeImmutable() > $order->getDeliveredAt()->modify('+7 days')) {
            return $this->json(['message' => 'The seven-day review window has expired.'], 409);
        }
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $restaurantRating = $this->ratingValue($data['restaurant_rating'] ?? null);
        $riderRating = $this->ratingValue($data['rider_rating'] ?? null);
        if (null === $restaurantRating && null === $riderRating) {
            return $this->json(['message' => 'At least one rating between 1 and 5 is required.'], 422);
        }
        if ((array_key_exists('restaurant_rating', $data) && null === $restaurantRating)
            || (array_key_exists('rider_rating', $data) && null === $riderRating)) {
            return $this->json(['message' => 'Ratings must be integers between 1 and 5.'], 422);
        }
        if (null !== $restaurantRating && null !== $order->getRestaurantReview()) {
            return $this->json(['message' => 'This restaurant has already been reviewed for the order.'], 409);
        }
        if (null !== $riderRating && null !== $order->getRiderReview()) {
            return $this->json(['message' => 'This rider has already been reviewed for the order.'], 409);
        }
        if (null !== $riderRating && null === $order->getRider()) {
            return $this->json(['message' => 'This order has no assigned rider to review.'], 422);
        }
        $comment = $this->optionalText($data['comment'] ?? null, 5000);
        if (false === $comment) {
            return $this->json(['message' => 'comment cannot exceed 5000 characters.'], 422);
        }
        $now = new \DateTimeImmutable();
        $created = [];
        if (null !== $restaurantRating) {
            $review = (new RestaurantReview())->setOrder($order)->setCustomer($this->customer())->setRestaurant($order->getRestaurant())
                ->setRating($restaurantRating)->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
            $order->setRestaurantReview($review);
            $this->entityManager->persist($review);
            $created['restaurant_review_id'] = $review;
        }
        if (null !== $riderRating) {
            $review = (new RiderReview())->setOrder($order)->setCustomer($this->customer())->setRider($order->getRider())
                ->setRating($riderRating)->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
            $order->setRiderReview($review);
            $this->entityManager->persist($review);
            $created['rider_review_id'] = $review;
        }
        $this->entityManager->flush();

        return $this->json(['message' => 'Review submitted.', 'reviews' => array_map(static fn ($review): ?string => $review->getId(), $created)], 201);
    }

    #[Route('/{id}/reports', name: 'reports', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function reports(string $id, Request $request): JsonResponse
    {
        $order = $this->ownedOrder($id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $type = OrderReportType::tryFrom((string) ($data['type'] ?? ''));
        $against = OrderReportAgainst::tryFrom((string) ($data['against'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $errors = [];
        if (null === $type) {
            $errors['type'][] = 'Invalid report type.';
        }
        if (null === $against || OrderReportAgainst::CUSTOMER === $against) {
            $errors['against'][] = 'against must be restaurant, rider, or platform.';
        }
        if ('' === $description || mb_strlen($description) > 5000) {
            $errors['description'][] = 'Description must be between 1 and 5000 characters.';
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }
        $now = new \DateTimeImmutable();
        $report = (new OrderReport())->setOrder($order)->setReportedBy($this->customerUser())->setType($type)->setAgainst($against)
            ->setDescription($description)->setStatus(OrderReportStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $this->json(['message' => 'Report submitted.', 'report' => [
            'id' => $report->getId(), 'type' => $report->getType()->value, 'against' => $report->getAgainst()->value,
            'description' => $report->getDescription(), 'status' => $report->getStatus()->value,
        ]], 201);
    }

    private function ownedOrder(string $id): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->andWhere('orders.id = :id')->setParameter('id', $id)
            ->andWhere('orders.customer = :customer')->setParameter('customer', $this->customer())
            ->getQuery()->getOneOrNullResult();
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Order not found.');
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function summary(Order $order): array
    {
        return [
            'id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $order->getStatus()->value,
            'restaurant' => ['id' => $order->getRestaurant()->getId(), 'name' => $order->getRestaurant()->getName(), 'logo_path' => $order->getRestaurant()->getLogoPath()],
            'item_count' => array_sum(array_map(static fn (OrderItem $item): int => $item->getQuantity(), $order->getItems()->toArray())),
            'total_amount' => $order->getTotalAmount(), 'payment_method' => $order->getPaymentMethod()->value,
            'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM), 'delivered_at' => $order->getDeliveredAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Order $order): array
    {
        $history = $order->getStatusHistory()->toArray();
        usort($history, static fn (OrderStatusHistory $left, OrderStatusHistory $right): int => ($left->getCreatedAt() <=> $right->getCreatedAt()) ?: ((int) $left->getId() <=> (int) $right->getId()));
        $address = $order->getCustomerAddress();

        return $this->summary($order) + [
            'subtotal' => $order->getSubtotal(), 'delivery_fee' => $order->getDeliveryFee(), 'service_fee' => $order->getServiceFee(),
            'discount_amount' => $order->getDiscountAmount(), 'tip_amount' => $order->getTipAmount(), 'customer_notes' => $order->getCustomerNotes(),
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
            'rider' => null === $order->getRider() ? null : ['id' => $order->getRider()?->getId(), 'name' => $order->getRider()?->getUser()->getName(), 'vehicle_type' => $order->getRider()?->getVehicleType()->value],
            'delivery_address' => ['id' => $address->getId(), 'label' => $address->getLabel(), 'address_line' => $address->getAddressLine(), 'landmark' => $address->getLandmark(), 'delivery_instructions' => $address->getDeliveryInstructions()],
            'items' => array_map($this->item(...), $order->getItems()->toArray()),
            'status_history' => array_map(static fn (OrderStatusHistory $entry): array => [
                'id' => $entry->getId(), 'status' => $entry->getStatus(), 'changed_by' => $entry->getChangedBy()->value,
                'note' => $entry->getNote(), 'created_at' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $history),
            'payment' => null === $order->getPayment() ? null : ['status' => $order->getPayment()?->getStatus()->value, 'amount' => $order->getPayment()?->getAmount(), 'refunded_amount' => $order->getPayment()?->getRefundedAmount()],
        ];
    }

    /** @return array<string, mixed> */
    private function item(OrderItem $item): array
    {
        return [
            'id' => $item->getId(), 'menu_item_id' => $item->getMenuItem()->getId(), 'name' => $item->getMenuItem()->getName(),
            'variant' => null === $item->getMenuItemVariant() ? null : ['id' => $item->getMenuItemVariant()?->getId(), 'name' => $item->getMenuItemVariant()?->getName()],
            'quantity' => $item->getQuantity(), 'unit_price' => $item->getUnitPrice(), 'special_instructions' => $item->getSpecialInstructions(),
            'addons' => array_map(static fn (OrderItemAddon $addon): array => ['id' => $addon->getMenuItemAddon()->getId(), 'name' => $addon->getMenuItemAddon()->getName(), 'price' => $addon->getPrice()], $item->getAddons()->toArray()),
        ];
    }

    private function ratingValue(mixed $value): ?int
    {
        $rating = filter_var($value, FILTER_VALIDATE_INT);

        return false === $rating || $rating < 1 || $rating > 5 ? null : $rating;
    }

    private function optionalText(mixed $value, int $maximum): string|false|null
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        $value = trim((string) $value);

        return mb_strlen($value) > $maximum ? false : $value;
    }
}
