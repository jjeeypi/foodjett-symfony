<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderItemAddon;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderPoolDecline;
use App\Entity\RiderPoolOffer;
use App\Entity\Voucher;
use App\Entity\VoucherRedemption;
use App\Message\OrderStatusChanged;
use App\Message\OrderUpdated;
use App\Message\RiderPoolOfferCreated;
use App\Message\RiderPoolOfferTaken;
use App\Repository\DeliveryZoneRepository;
use App\Repository\OrderRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\VoucherRedemptionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only writer of Order.status. Every status it sets is recorded as authorized so that
 * StatusGuardListener can reject any other change to the column at flush time.
 *
 * It also owns the rest of the order lifecycle, in sections below: checkout, the rider pool,
 * rider pool escalation and rider pay. Maker has no generator for services, and the project
 * rules allow only this class and PaymentTransitionService to be written by hand.
 */
final class OrderTransitionService
{
    /**
     * Statuses this service set and has not yet seen flushed, keyed by entity. Shared by every
     * instance in the process; a WeakMap so entries disappear together with their entities.
     *
     * @var \WeakMap<Order, string>|null
     */
    private static ?\WeakMap $authorizedStatuses = null;

    /** Rider pay defaults, used when the platform settings are missing. */
    public const DEFAULT_BASE_PAY = '40.00';

    public const DEFAULT_DISTANCE_RATE_PER_KM = '10.00';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlatformSettingRepository $settings,
        private readonly MessageBusInterface $bus,
        private readonly PaymentTransitionService $payments,
        private readonly OrderRepository $orders,
        private readonly DeliveryZoneRepository $deliveryZones,
        private readonly VoucherRedemptionRepository $voucherRedemptions,
    ) {
        self::$authorizedStatuses ??= new \WeakMap();
    }

    /**
     * Whether this service set $order's status to $status; a matching authorization is used up.
     */
    public function consumeAuthorizedStatus(Order $order, string $status): bool
    {
        if ((self::$authorizedStatuses[$order] ?? null) !== $status) {
            return false;
        }
        unset(self::$authorizedStatuses[$order]);

        return true;
    }

    /**
     * @param null|callable(Order, \DateTimeImmutable): void $applyChanges
     */
    public function transition(
        Order $order,
        string $to,
        string $changedBy,
        ?string $note = null,
        ?callable $applyChanges = null,
    ): Order {
        return $this->transitionSequence($order, [$to], $changedBy, [$note], $applyChanges);
    }

    /**
     * Atomically records a sequence such as accepted -> preparing -> finding_rider.
     * Events are deliberately dispatched only after wrapInTransaction() commits.
     *
     * @param non-empty-list<string>                   $statuses
     * @param list<?string>                                 $notes
     * @param null|callable(Order, \DateTimeImmutable): void $applyChanges
     */
    public function transitionSequence(
        Order $order,
        array $statuses,
        string $changedBy,
        array $notes = [],
        ?callable $applyChanges = null,
    ): Order {
        if (null === $order->getId()) {
            throw new \InvalidArgumentException('An order must be persisted before it can transition.');
        }
        if ([] === $statuses) {
            throw new \InvalidArgumentException('At least one destination status is required.');
        }
        $this->assertValidSequence($order->getStatus(), $statuses);

        /** @var list<OrderStatusChanged> $events */
        $events = [];

        /** @var Order $updatedOrder */
        $updatedOrder = $this->entityManager->wrapInTransaction(function () use (
            $order,
            $statuses,
            $changedBy,
            $notes,
            $applyChanges,
            &$events,
        ): Order {
            $locked = $this->entityManager->find(Order::class, $order->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof Order) {
                throw new \RuntimeException('Order no longer exists.');
            }

            $now = new \DateTimeImmutable();
            if (null !== $applyChanges) {
                $applyChanges($locked, $now);
            }

            foreach ($statuses as $index => $status) {
                $from = $locked->getStatus();
                if (!Order::canTransition($from, $status)) {
                    throw new \DomainException(sprintf('Order cannot transition from "%s" to "%s".', $from, $status));
                }

                $note = $notes[$index] ?? null;
                $this->applyStatusSideEffects($locked, $status, $now);
                self::$authorizedStatuses[$locked] = $status;
                $locked
                    ->setStatus($status)
                    ->setUpdatedAt($now);

                $history = (new OrderStatusHistory())
                    ->setOrder($locked)
                    ->setStatus($status)
                    ->setChangedBy($changedBy)
                    ->setNote($note)
                    ->setCreatedAt($now);
                $locked->addStatusHistory($history);
                $this->entityManager->persist($history);

                $events[] = new OrderStatusChanged(
                    orderId: (string) $locked->getId(),
                    previousStatus: $from,
                    currentStatus: $status,
                    changedBy: $changedBy,
                    note: $note,
                    changedAt: $now,
                );
            }

            $this->entityManager->flush();

            return $locked;
        });

        foreach ($events as $event) {
            $this->bus->dispatch($event);
            if (Order::STATUS_FINDING_RIDER === $event->currentStatus) {
                $this->bus->dispatch(new RiderPoolOfferCreated($event->orderId, $event->changedAt));
            } elseif (Order::STATUS_FINDING_RIDER === $event->previousStatus) {
                $this->bus->dispatch(new RiderPoolOfferTaken(
                    $event->orderId,
                    $event->currentStatus,
                    $event->changedAt,
                ));
            }
        }

        return $updatedOrder;
    }

    /**
     * Records an order-domain update that does not replace the current delivery status.
     * This supports parallel food milestones such as ready while rider search is active.
     *
     * @param callable(Order, \DateTimeImmutable): void $applyChanges
     */
    public function recordUpdate(
        Order $order,
        string $changedBy,
        string $kind,
        ?string $note,
        callable $applyChanges,
        ?string $historyStatus = null,
    ): Order {
        if (null === $order->getId()) {
            throw new \InvalidArgumentException('An order must be persisted before it can be updated.');
        }

        $changedAt = new \DateTimeImmutable();
        /** @var Order $updatedOrder */
        $updatedOrder = $this->entityManager->wrapInTransaction(function () use (
            $order,
            $changedBy,
            $note,
            $applyChanges,
            $historyStatus,
            $changedAt,
        ): Order {
            $locked = $this->entityManager->find(Order::class, $order->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof Order) {
                throw new \RuntimeException('Order no longer exists.');
            }

            $applyChanges($locked, $changedAt);
            $locked->setUpdatedAt($changedAt);
            $history = (new OrderStatusHistory())
                ->setOrder($locked)
                ->setStatus(($historyStatus ?? $locked->getStatus()))
                ->setChangedBy($changedBy)
                ->setNote($note)
                ->setCreatedAt($changedAt);
            $locked->addStatusHistory($history);
            $this->entityManager->persist($history);
            $this->entityManager->flush();

            return $locked;
        });

        $this->bus->dispatch(new OrderUpdated(
            orderId: (string) $updatedOrder->getId(),
            currentStatus: $updatedOrder->getStatus(),
            changedBy: $changedBy,
            kind: $kind,
            note: $note,
            changedAt: $changedAt,
        ));

        return $updatedOrder;
    }

    private function applyStatusSideEffects(Order $order, string $status, \DateTimeImmutable $now): void
    {
        if (Order::STATUS_FINDING_RIDER === $status) {
            if (null === $order->getRiderSearchStartedAt()) {
                $order->setRiderSearchStartedAt($now);
            }
            if (null === $order->getRiderPoolOffer()) {
                $radius = max(0.1, $this->settings->float('rider_search_initial_radius_km', 3.0));
                $offer = (new RiderPoolOffer())
                    ->setOrder($order)
                    ->setSearchRadiusKm(number_format($radius, 1, '.', ''))
                    ->setEscalationStage(RiderPoolOffer::STAGE_INITIAL)
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);
                $order->setRiderPoolOffer($offer);
                $this->entityManager->persist($offer);
            }
        }

        if (Order::STATUS_RIDER_ASSIGNED === $status) {
            if (null === $order->getRider()) {
                throw new \DomainException('A rider must be assigned before entering rider_assigned status.');
            }
            $order
                ->setRiderAssignedAt($now)
                ->setPickupCode((string) random_int(1000, 9999));
        }

        if (Order::STATUS_DELIVERED === $status) {
            $order->setDeliveredAt($now);
        }
    }

    /** @param list<string> $statuses */
    private function assertValidSequence(string $from, array $statuses): void
    {
        foreach ($statuses as $status) {
            if (!Order::canTransition($from, $status)) {
                throw new \DomainException(sprintf('Order cannot transition from "%s" to "%s".', $from, $status));
            }
            $from = $status;
        }
    }

    // ----------------------------------------------------------------------
    // Checkout: prices the cart, then places the order with its payment and first history row.
    // ----------------------------------------------------------------------

    /**
     * The caller must reuse checkout_token when retrying the same submission. The database
     * unique constraint is the final guard; this early lookup makes normal retries idempotent.
     *
     * @param array<string, mixed> $input
     */
    public function placeOrder(Customer $customer, array $input): array
    {
        $token = strtolower(trim(is_string($input['checkout_token'] ?? null) ? $input['checkout_token'] : ''));
        if (!$this->isUuid($token)) {
            throw new UnprocessableEntityHttpException('checkout_token must be a valid UUID.');
        }

        $existing = $this->entityManager->getRepository(Order::class)->findOneBy(['checkoutToken' => $token]);
        if ($existing instanceof Order) {
            if ($existing->getCustomer() !== $customer) {
                throw new ConflictHttpException('This checkout token is already in use.');
            }

            return ['order' => $existing, 'created' => false];
        }

        /** @var array{order: Order, created: bool} $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($customer, $input, $token): array {
            if (null === $customer->getId()) {
                throw new \InvalidArgumentException('The customer must be persisted before checkout.');
            }
            // Serializing checkouts per customer closes the race between the token lookup and insert.
            // The unique checkout_token index remains the database-level final guard.
            $lockedCustomer = $this->entityManager->find(Customer::class, $customer->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedCustomer instanceof Customer) {
                throw new NotFoundHttpException('Customer profile not found.');
            }
            $existing = $this->entityManager->getRepository(Order::class)->findOneBy(['checkoutToken' => $token]);
            if ($existing instanceof Order) {
                if ($existing->getCustomer() !== $lockedCustomer) {
                    throw new ConflictHttpException('This checkout token is already in use.');
                }

                return ['order' => $existing, 'created' => false];
            }

            $now = new \DateTimeImmutable();
            $quote = $this->checkoutQuote($lockedCustomer, $input, $now, true);
            $restaurant = $quote['restaurant'];
            $address = $quote['address'];
            $paymentMethod = $quote['payment_method'];
            $notes = $quote['notes'];
            $itemRows = $quote['item_rows'];
            $voucher = $quote['voucher'];
            $order = (new Order())
                ->setOrderNumber($this->newOrderNumber())
                ->setCheckoutToken($token)
                ->setCustomer($lockedCustomer)
                ->setRestaurant($restaurant)
                ->setCustomerAddress($address)
                ->setStatus(Order::STATUS_PLACED)
                ->setSubtotal($quote['subtotal'])
                ->setDeliveryFee($quote['delivery_fee'])
                ->setServiceFee($quote['service_fee'])
                ->setDiscountAmount($quote['discount'])
                ->setTipAmount($quote['tip'])
                ->setTotalAmount($quote['total'])
                ->setCommissionAmount($quote['commission'])
                ->setPaymentMethod($paymentMethod)
                ->setCustomerNotes($notes)
                ->setPlacedAt($now)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $this->entityManager->persist($order);

            foreach ($itemRows as $row) {
                $orderItem = (new OrderItem())
                    ->setOrder($order)
                    ->setMenuItem($row['item'])
                    ->setMenuItemVariant($row['variant'])
                    ->setQuantity($row['quantity'])
                    ->setUnitPrice($row['unit_price'])
                    ->setSpecialInstructions($row['instructions'])
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);
                $order->addItem($orderItem);
                $this->entityManager->persist($orderItem);

                foreach ($row['addons'] as $addon) {
                    $snapshot = (new OrderItemAddon())
                        ->setOrderItem($orderItem)
                        ->setMenuItemAddon($addon)
                        ->setPrice($addon->getPrice())
                        ->setCreatedAt($now)
                        ->setUpdatedAt($now);
                    $orderItem->addAddon($snapshot);
                    $this->entityManager->persist($snapshot);
                }
            }

            $orderHistory = (new OrderStatusHistory())
                ->setOrder($order)
                ->setStatus(Order::STATUS_PLACED)
                ->setChangedBy(Order::ACTOR_CUSTOMER)
                ->setNote('Order placed by customer.')
                ->setCreatedAt($now);
            $order->addStatusHistory($orderHistory);
            $this->entityManager->persist($orderHistory);

            $paymentStatus = Payment::METHOD_COD === $paymentMethod ? Payment::STATUS_PENDING : Payment::STATUS_PAID;
            $payment = (new Payment())
                ->setOrder($order)
                ->setMethod($paymentMethod)
                ->setStatus($paymentStatus)
                ->setAmount($quote['total'])
                ->setPaidAt(Payment::STATUS_PAID === $paymentStatus ? $now : null)
                ->setTransactionReference(Payment::STATUS_PAID === $paymentStatus ? 'SIMULATED-'.strtoupper(bin2hex(random_bytes(8))) : null)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $order->setPayment($payment);
            $this->entityManager->persist($payment);
            $paymentHistory = (new PaymentStatusHistory())
                ->setPayment($payment)
                ->setFromStatus(null)
                ->setToStatus($paymentStatus)
                ->setChangedBy(Payment::ACTOR_SYSTEM)
                ->setNote(Payment::STATUS_PAID === $paymentStatus ? 'Simulated payment completed.' : 'COD payment awaiting collection.')
                ->setCreatedAt($now);
            $payment->addStatusHistory($paymentHistory);
            $this->entityManager->persist($paymentHistory);

            if ($voucher instanceof Voucher) {
                $redemption = (new VoucherRedemption())
                    ->setVoucher($voucher)
                    ->setOrder($order)
                    ->setCustomer($lockedCustomer)
                    ->setDiscountApplied($quote['discount'])
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);
                $order->setVoucherRedemption($redemption);
                $this->entityManager->persist($redemption);
            }

            $this->entityManager->flush();

            return ['order' => $order, 'created' => true];
        });

        if ($result['created']) {
            $this->bus->dispatch(new OrderStatusChanged(
                orderId: (string) $result['order']->getId(),
                previousStatus: null,
                currentStatus: Order::STATUS_PLACED,
                changedBy: Order::ACTOR_CUSTOMER,
                note: 'Order placed by customer.',
                changedAt: $result['order']->getPlacedAt(),
            ));
        }

        return $result;
    }

    /**
     * Validates the same cart/address/payment input as checkout and returns server-authoritative
     * totals without creating an order, payment, redemption, or history row.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function previewCheckout(Customer $customer, array $input): array
    {
        $quote = $this->checkoutQuote($customer, $input, new \DateTimeImmutable(), false);

        $items = array_map(function (array $row): array {
            $unitTotal = $row['unit_price'];
            foreach ($row['addons'] as $addon) {
                $unitTotal = bcadd($unitTotal, $addon->getPrice(), 2);
            }

            return [
                'menu_item_id' => $row['item']->getId(),
                'name' => $row['item']->getName(),
                'quantity' => $row['quantity'],
                'variant' => $row['variant'] instanceof MenuItemVariant ? [
                    'id' => $row['variant']->getId(),
                    'name' => $row['variant']->getName(),
                ] : null,
                'addons' => array_map(static fn (MenuItemAddon $addon): array => [
                    'id' => $addon->getId(),
                    'name' => $addon->getName(),
                    'price' => $addon->getPrice(),
                ], $row['addons']),
                'unit_total' => $unitTotal,
                'line_total' => bcmul($unitTotal, (string) $row['quantity'], 2),
            ];
        }, $quote['item_rows']);

        return [
            'restaurant_id' => $quote['restaurant']->getId(),
            'customer_address_id' => $quote['address']->getId(),
            'address_covered' => true,
            'payment_method' => $quote['payment_method'],
            'items' => $items,
            'subtotal' => $quote['subtotal'],
            'delivery_fee' => $quote['delivery_fee'],
            'service_fee' => $quote['service_fee'],
            'discount_amount' => $quote['discount'],
            'tip_amount' => $quote['tip'],
            'total_amount' => $quote['total'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     restaurant: Restaurant,
     *     address: CustomerAddress,
     *     payment_method: string,
     *     tip: numeric-string,
     *     notes: ?string,
     *     item_rows: list<array{item: MenuItem, variant: ?MenuItemVariant, addons: list<MenuItemAddon>, quantity: int, unit_price: numeric-string, instructions: ?string}>,
     *     subtotal: numeric-string,
     *     delivery_fee: numeric-string,
     *     service_fee: numeric-string,
     *     voucher: ?Voucher,
     *     discount: numeric-string,
     *     total: numeric-string,
     *     commission: numeric-string
     * }
     *
     * Every amount is an exact decimal string with two places, computed with bcmath.
     */
    private function checkoutQuote(Customer $customer, array $input, \DateTimeImmutable $now, bool $lockVoucher): array
    {
        $restaurant = $this->checkoutRestaurant($input['restaurant_id'] ?? null);
        $address = $this->checkoutAddress($input['customer_address_id'] ?? null, $customer);
        $paymentMethod = $this->paymentMethod($input['payment_method'] ?? null);
        $tip = $this->nonNegativeMoney($input['tip_amount'] ?? 0, 'tip_amount');
        $notes = $this->optionalText($input['customer_notes'] ?? null, 2000, 'customer_notes');

        $this->assertRestaurantCanAcceptOrders($restaurant, $now);
        if (!$this->deliveryZones->isCovered((float) $address->getLatitude(), (float) $address->getLongitude())) {
            throw new UnprocessableEntityHttpException('The selected address is outside the active delivery zones.');
        }

        $selections = $input['items'] ?? null;
        if (!is_array($selections) || [] === $selections) {
            throw new UnprocessableEntityHttpException('At least one cart item is required.');
        }

        [$itemRows, $subtotal] = $this->priceItems($selections, $restaurant, $now);
        if (-1 === bccomp($subtotal, $restaurant->getMinOrderAmount(), 2)) {
            throw new UnprocessableEntityHttpException('The order subtotal does not meet the restaurant minimum.');
        }

        $distanceKm = DeliveryZoneRepository::distanceKm(
            (float) $restaurant->getLatitude(),
            (float) $restaurant->getLongitude(),
            (float) $address->getLatitude(),
            (float) $address->getLongitude(),
        );
        // The haversine distance is a geographic measurement; it becomes an exact decimal before any money maths.
        $distance = sprintf('%.4F', $distanceKm);
        $deliveryFee = bcround(bcadd(
            $this->settings->decimal('delivery_base_fee', '35.00'),
            bcmul($this->settings->decimal('delivery_fee_per_km', '10.00'), $distance, 6),
            6,
        ), 2);
        $serviceFee = $this->settings->decimal('service_fee', '0.00');

        $voucher = $this->checkoutVoucher($input['voucher_code'] ?? null, $lockVoucher);
        $discount = $voucher instanceof Voucher
            ? $this->voucherDiscount($voucher, $customer, $restaurant, $subtotal, $deliveryFee, $now)
            : '0.00';
        $total = bcsub(bcadd(bcadd(bcadd($subtotal, $deliveryFee, 2), $serviceFee, 2), $tip, 2), $discount, 2);
        if (-1 === bccomp($total, '0', 2)) {
            $total = '0.00';
        }
        // Commission is based on food subtotal. Voucher funding ownership is not yet modelled.
        $commission = bcround(bcdiv(bcmul($subtotal, $restaurant->getCommissionRate(), 6), '100', 6), 2);

        return [
            'restaurant' => $restaurant,
            'address' => $address,
            'payment_method' => $paymentMethod,
            'tip' => $tip,
            'notes' => $notes,
            'item_rows' => $itemRows,
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'service_fee' => $serviceFee,
            'voucher' => $voucher,
            'discount' => $discount,
            'total' => $total,
            'commission' => $commission,
        ];
    }

    private function checkoutRestaurant(mixed $id): Restaurant
    {
        if ((!is_int($id) && !is_string($id)) || '' === (string) $id) {
            throw new UnprocessableEntityHttpException('restaurant_id is required.');
        }
        $restaurant = $this->entityManager->find(Restaurant::class, (string) $id);
        if (!$restaurant instanceof Restaurant) {
            throw new NotFoundHttpException('Restaurant not found.');
        }

        return $restaurant;
    }

    private function checkoutAddress(mixed $id, Customer $customer): CustomerAddress
    {
        if ((!is_int($id) && !is_string($id)) || '' === (string) $id) {
            throw new UnprocessableEntityHttpException('customer_address_id is required.');
        }
        $address = $this->entityManager->find(CustomerAddress::class, (string) $id);
        if (!$address instanceof CustomerAddress || $address->getCustomer() !== $customer) {
            throw new NotFoundHttpException('Delivery address not found.');
        }

        return $address;
    }

    private function paymentMethod(mixed $value): string
    {
        $method = is_string($value) ? (in_array($value, Payment::METHODS, true) ? $value : null) : null;
        if (null === $method) {
            throw new UnprocessableEntityHttpException('payment_method must be cod, gcash, or card.');
        }

        return $method;
    }

    private function assertRestaurantCanAcceptOrders(Restaurant $restaurant, \DateTimeImmutable $now): void
    {
        if (Restaurant::APPROVAL_APPROVED !== $restaurant->getApprovalStatus()) {
            throw new UnprocessableEntityHttpException('This restaurant is not approved for orders.');
        }
        if (Restaurant::OPERATING_OPEN !== $restaurant->getOperatingStatus()) {
            throw new UnprocessableEntityHttpException('This restaurant is currently closed.');
        }

        // Operating hours are optional until the restaurant configures a
        // schedule. In that state, the explicit open/closed toggle is the
        // source of truth instead of silently making an open store unusable.
        if ($restaurant->getOperatingHours()->isEmpty()) {
            return;
        }

        $today = (int) $now->format('w');
        $previousDay = (6 + $today) % 7;
        $time = $now->format('H:i:s');
        foreach ($restaurant->getOperatingHours() as $hours) {
            $opens = $hours->getOpensAt()->format('H:i:s');
            $closes = $hours->getClosesAt()->format('H:i:s');
            $overnight = $opens > $closes;
            if ($hours->getDayOfWeek() === $today && ((!$overnight && $time >= $opens && $time <= $closes) || ($overnight && $time >= $opens))) {
                return;
            }
            if ($overnight && $hours->getDayOfWeek() === $previousDay && $time <= $closes) {
                return;
            }
        }

        throw new UnprocessableEntityHttpException('This restaurant is outside its operating hours.');
    }

    /**
     * @param array<mixed> $selections
     * @return array{0: list<array{item: MenuItem, variant: ?MenuItemVariant, addons: list<MenuItemAddon>, quantity: int, unit_price: numeric-string, instructions: ?string}>, 1: numeric-string}
     */
    private function priceItems(array $selections, Restaurant $restaurant, \DateTimeImmutable $now): array
    {
        if (count($selections) > 100) {
            throw new UnprocessableEntityHttpException('A checkout may contain at most 100 item rows.');
        }
        $rows = [];
        $subtotal = '0.00';
        foreach ($selections as $selection) {
            if (!is_array($selection)) {
                throw new UnprocessableEntityHttpException('Each cart item must be an object.');
            }
            $item = $this->entityManager->find(MenuItem::class, (string) ($selection['menu_item_id'] ?? ''));
            if (!$item instanceof MenuItem || $item->getMenuCategory()->getRestaurant() !== $restaurant) {
                throw new UnprocessableEntityHttpException('A selected menu item does not belong to this restaurant.');
            }
            if (!$item->isAvailable() || !$this->isWithinTimeWindow($item->getAvailableFrom(), $item->getAvailableUntil(), $now)) {
                throw new UnprocessableEntityHttpException(sprintf('%s is not currently available.', $item->getName()));
            }
            $quantity = filter_var($selection['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (false === $quantity || $quantity < 1 || $quantity > 99) {
                throw new UnprocessableEntityHttpException('Each item quantity must be between 1 and 99.');
            }

            $variant = null;
            if (isset($selection['variant_id']) && null !== $selection['variant_id'] && '' !== $selection['variant_id']) {
                $variant = $this->entityManager->find(MenuItemVariant::class, (string) $selection['variant_id']);
                if (!$variant instanceof MenuItemVariant || $variant->getMenuItem() !== $item) {
                    throw new UnprocessableEntityHttpException('A selected variant does not belong to its menu item.');
                }
            }

            $addons = [];
            $addonIds = $selection['addon_ids'] ?? [];
            if (!is_array($addonIds)) {
                throw new UnprocessableEntityHttpException('addon_ids must be an array.');
            }
            foreach (array_unique(array_map('strval', $addonIds)) as $addonId) {
                $addon = $this->entityManager->find(MenuItemAddon::class, $addonId);
                if (!$addon instanceof MenuItemAddon || $addon->getMenuItem() !== $item || !$addon->isAvailable()) {
                    throw new UnprocessableEntityHttpException('A selected add-on is unavailable or does not belong to its menu item.');
                }
                $addons[] = $addon;
            }

            $unitPrice = bcadd($item->getBasePrice(), $variant instanceof MenuItemVariant ? $variant->getPriceDelta() : '0', 2);
            if (-1 === bccomp($unitPrice, '0', 2)) {
                throw new UnprocessableEntityHttpException('A selected variant results in an invalid item price.');
            }
            $unitTotal = $unitPrice;
            foreach ($addons as $addon) {
                $unitTotal = bcadd($unitTotal, $addon->getPrice(), 2);
            }
            $subtotal = bcadd($subtotal, bcmul($unitTotal, (string) $quantity, 2), 2);
            $rows[] = [
                'item' => $item,
                'variant' => $variant,
                'addons' => $addons,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'instructions' => $this->optionalText($selection['special_instructions'] ?? null, 1000, 'special_instructions'),
            ];
        }

        return [$rows, $subtotal];
    }

    private function isWithinTimeWindow(?\DateTimeImmutable $from, ?\DateTimeImmutable $until, \DateTimeImmutable $now): bool
    {
        if (null === $from && null === $until) {
            return true;
        }
        $time = $now->format('H:i:s');
        $start = $from?->format('H:i:s');
        $end = $until?->format('H:i:s');
        if (null === $start) {
            return $time <= $end;
        }
        if (null === $end) {
            return $time >= $start;
        }

        return $start <= $end ? $time >= $start && $time <= $end : $time >= $start || $time <= $end;
    }

    private function checkoutVoucher(mixed $code, bool $lock): ?Voucher
    {
        $code = strtoupper(trim(is_string($code) ? $code : ''));
        if ('' === $code) {
            return null;
        }
        $voucher = $this->entityManager->getRepository(Voucher::class)->findOneBy(['code' => $code]);
        if (!$voucher instanceof Voucher) {
            throw new UnprocessableEntityHttpException('Voucher code is invalid.');
        }
        if ($lock) {
            $this->entityManager->lock($voucher, LockMode::PESSIMISTIC_WRITE);
        }

        return $voucher;
    }

    private function voucherDiscount(
        Voucher $voucher,
        Customer $customer,
        Restaurant $restaurant,
        string $subtotal,
        string $deliveryFee,
        \DateTimeImmutable $now,
    ): string {
        if (!$voucher->isActive() || (null !== $voucher->getStartsAt() && $now < $voucher->getStartsAt()) || (null !== $voucher->getEndsAt() && $now > $voucher->getEndsAt())) {
            throw new UnprocessableEntityHttpException('Voucher is not active.');
        }
        if (-1 === bccomp($subtotal, $voucher->getMinOrderAmount(), 2)) {
            throw new UnprocessableEntityHttpException('The order does not meet the voucher minimum.');
        }
        if (Voucher::SCOPE_RESTAURANT === $voucher->getScope() && $voucher->getRestaurant() !== $restaurant) {
            throw new UnprocessableEntityHttpException('Voucher does not apply to this restaurant.');
        }

        $totalUses = $this->voucherRedemptions->countForVoucher($voucher);
        if (null !== $voucher->getUsageLimitTotal() && $totalUses >= $voucher->getUsageLimitTotal()) {
            throw new UnprocessableEntityHttpException('Voucher usage limit has been reached.');
        }
        $customerUses = $this->voucherRedemptions->countForVoucherAndCustomer($voucher, $customer);
        if ($customerUses >= $voucher->getUsageLimitPerCustomer()) {
            throw new UnprocessableEntityHttpException('You have already reached this voucher usage limit.');
        }

        $value = $voucher->getValue() ?? '0';
        if (-1 === bccomp($value, '0', 2)) {
            $value = '0';
        }

        $discount = match ($voucher->getType()) {
            Voucher::TYPE_PERCENTAGE => bcround(bcdiv(bcmul($subtotal, 1 === bccomp($value, '100', 2) ? '100' : $value, 6), '100', 6), 2),
            Voucher::TYPE_FIXED => bcround($value, 2),
            Voucher::TYPE_FREE_DELIVERY => $deliveryFee,
        };

        // A voucher never discounts more than the food subtotal (free delivery is bounded by the fee itself).
        return Voucher::TYPE_FREE_DELIVERY !== $voucher->getType() && 1 === bccomp($discount, $subtotal, 2) ? $subtotal : $discount;
    }

    /**
     * Validates a client-supplied amount and returns it as an exact two-place decimal string.
     *
     * @return numeric-string
     */
    private function nonNegativeMoney(mixed $value, string $field): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new UnprocessableEntityHttpException(sprintf('%s must be a non-negative amount.', $field));
        }
        $amount = trim((string) $value);
        if (!is_numeric($amount) || -1 === bccomp($amount, '0', 4)) {
            throw new UnprocessableEntityHttpException(sprintf('%s must be a non-negative amount.', $field));
        }

        return bcround($amount, 2);
    }

    private function optionalText(mixed $value, int $maxLength, string $field): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new UnprocessableEntityHttpException(sprintf('%s must be at most %d characters.', $field, $maxLength));
        }

        return trim($value);
    }

    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private function newOrderNumber(): string
    {
        return 'FJ-'.(new \DateTimeImmutable())->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
    }

    // ----------------------------------------------------------------------
    // Rider pool: which orders a rider sees, and accepting or declining them.
    // ----------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function visiblePoolOrders(Rider $rider): array
    {
        if (Rider::AVAILABILITY_AVAILABLE !== $rider->getAvailabilityStatus()
            || null === $rider->getCurrentLatitude()
            || null === $rider->getCurrentLongitude()) {
            return [];
        }

        $orders = $this->orders->findAwaitingRiderForPool();

        $visible = [];
        foreach ($orders as $order) {
            $offer = $order->getRiderPoolOffer();
            if (!$offer instanceof RiderPoolOffer || $this->riderSkippedOrder($order, $rider)) {
                continue;
            }
            if (Payment::METHOD_COD === $order->getPaymentMethod() && $this->hasReachedCashLimit($rider)) {
                continue;
            }

            $pickupDistance = DeliveryZoneRepository::distanceKm(
                (float) $rider->getCurrentLatitude(),
                (float) $rider->getCurrentLongitude(),
                (float) $order->getRestaurant()->getLatitude(),
                (float) $order->getRestaurant()->getLongitude(),
            );
            if ($pickupDistance > (float) $offer->getSearchRadiusKm()) {
                continue;
            }
            $deliveryDistance = DeliveryZoneRepository::distanceKm(
                (float) $order->getRestaurant()->getLatitude(),
                (float) $order->getRestaurant()->getLongitude(),
                (float) $order->getCustomerAddress()->getLatitude(),
                (float) $order->getCustomerAddress()->getLongitude(),
            );
            $pay = $this->estimateRiderPay($pickupDistance, $deliveryDistance, $offer);

            $visible[] = [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'restaurant' => [
                    'name' => $order->getRestaurant()->getName(),
                    'latitude' => $order->getRestaurant()->getLatitude(),
                    'longitude' => $order->getRestaurant()->getLongitude(),
                ],
                'pickup_distance_km' => $this->formatKm($pickupDistance),
                'delivery_distance_km' => $this->formatKm($deliveryDistance),
                'estimated_pay' => $pay['total'],
                'pay_breakdown' => $pay,
                'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
                'search_radius_km' => $offer->getSearchRadiusKm(),
                'escalation_stage' => $offer->getEscalationStage(),
                'payment_method' => $order->getPaymentMethod(),
            ];
        }

        return $visible;
    }

    public function hasReachedCashLimit(Rider $rider): bool
    {
        return -1 !== bccomp($rider->getCashOnHand(), $rider->getCashRemitLimit(), 2);
    }

    /** @return array{code: string, message: string}|null */
    public function emptyPoolReason(Rider $rider): ?array
    {
        $orders = $this->orders->findAwaitingRiderWithRestaurant();

        $nearest = null;
        $radius = null;
        $codBlocked = false;
        foreach ($orders as $order) {
            $offer = $order->getRiderPoolOffer();
            if (!$offer instanceof RiderPoolOffer || $this->riderSkippedOrder($order, $rider)) {
                continue;
            }
            if (Payment::METHOD_COD === $order->getPaymentMethod() && $this->hasReachedCashLimit($rider)) {
                $codBlocked = true;
                continue;
            }
            $distance = DeliveryZoneRepository::distanceKm(
                (float) $rider->getCurrentLatitude(),
                (float) $rider->getCurrentLongitude(),
                (float) $order->getRestaurant()->getLatitude(),
                (float) $order->getRestaurant()->getLongitude(),
            );
            if (null === $nearest || $distance < $nearest) {
                $nearest = $distance;
                $radius = (float) $offer->getSearchRadiusKm();
            }
        }
        if (null !== $nearest && null !== $radius && $nearest > $radius) {
            return [
                'code' => 'outside_radius',
                'message' => sprintf(
                    'The nearest available pickup is %.2f km away, outside its current %.2f km search radius. Refresh your location and check that the restaurant map pin is correct.',
                    $nearest,
                    $radius,
                ),
            ];
        }
        if ($codBlocked) {
            return ['code' => 'cash_limit', 'message' => 'Your cash-on-hand limit is reached. Submit a remittance before accepting another COD order.'];
        }

        return null;
    }

    public function acceptPoolOrder(Order $order, Rider $rider, bool $adminOverride = false): Order
    {
        if (null === $rider->getId()) {
            throw new \InvalidArgumentException('The rider must be persisted before accepting an order.');
        }

        return $this->transition(
            $order,
            Order::STATUS_RIDER_ASSIGNED,
            $adminOverride ? Order::ACTOR_ADMIN : Order::ACTOR_RIDER,
            $adminOverride ? 'Rider manually assigned by admin.' : 'Rider accepted the pool offer.',
            function (Order $lockedOrder, \DateTimeImmutable $now) use ($rider, $adminOverride): void {
                $lockedRider = $this->entityManager->find(Rider::class, $rider->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$lockedRider instanceof Rider) {
                    throw new NotFoundHttpException('Rider profile not found.');
                }
                if (Order::STATUS_FINDING_RIDER !== $lockedOrder->getStatus() || null !== $lockedOrder->getRider()) {
                    throw new ConflictHttpException('This order was just taken by another rider.');
                }

                $pickupDistance = $this->assertRiderEligible($lockedOrder, $lockedRider, $adminOverride);
                $lockedOrder->setRider($lockedRider);
                $lockedRider
                    ->setAvailabilityStatus(Rider::AVAILABILITY_BUSY)
                    ->setUpdatedAt($now);
                $lockedOrder->getRiderPoolOffer()?->setAcceptedPickupDistanceKm(
                    number_format(round($pickupDistance, 4), 4, '.', ''),
                )->setUpdatedAt($now);
                if ($adminOverride) {
                    $lockedOrder->getRiderPoolOffer()?->setAdminAssigned(true)->setUpdatedAt($now);
                }
            },
        );
    }

    public function declinePoolOrder(Order $order, Rider $rider): RiderPoolDecline
    {
        if (null === $order->getId() || null === $rider->getId()) {
            throw new \InvalidArgumentException('The order and rider must be persisted before declining.');
        }

        /** @var RiderPoolDecline $decline */
        $decline = $this->entityManager->wrapInTransaction(function () use ($order, $rider): RiderPoolDecline {
            $lockedOrder = $this->entityManager->find(Order::class, $order->getId(), LockMode::PESSIMISTIC_WRITE);
            $lockedRider = $this->entityManager->find(Rider::class, $rider->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedOrder instanceof Order || !$lockedRider instanceof Rider) {
                throw new NotFoundHttpException('Order or rider no longer exists.');
            }
            if (Order::STATUS_FINDING_RIDER !== $lockedOrder->getStatus() || null !== $lockedOrder->getRider()) {
                throw new ConflictHttpException('This order was just taken by another rider.');
            }
            $this->assertRiderEligible($lockedOrder, $lockedRider, false, checkActiveOrder: false, checkCodLimit: false);

            $decline = $this->entityManager->getRepository(RiderPoolDecline::class)->findOneBy([
                'order' => $lockedOrder,
                'rider' => $lockedRider,
            ]);
            if (!$decline instanceof RiderPoolDecline) {
                $decline = (new RiderPoolDecline())
                    ->setOrder($lockedOrder)
                    ->setRider($lockedRider)
                    ->setCreatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($decline);
            }
            $decline->setAction(RiderPoolDecline::ACTION_SKIPPED);
            $this->entityManager->flush();

            return $decline;
        });

        return $decline;
    }

    private function assertRiderEligible(
        Order $order,
        Rider $rider,
        bool $adminOverride,
        bool $checkActiveOrder = true,
        bool $checkCodLimit = true,
    ): float {
        if (Rider::APPROVAL_APPROVED !== $rider->getApprovalStatus()) {
            throw new UnprocessableEntityHttpException('Only approved riders can be assigned to orders.');
        }
        if (null === $rider->getCurrentLatitude() || null === $rider->getCurrentLongitude()) {
            throw new UnprocessableEntityHttpException('Current rider location is required.');
        }
        $offer = $order->getRiderPoolOffer();
        if (!$offer instanceof RiderPoolOffer) {
            throw new ConflictHttpException('This order has no active rider pool offer.');
        }
        $distance = DeliveryZoneRepository::distanceKm(
            (float) $rider->getCurrentLatitude(),
            (float) $rider->getCurrentLongitude(),
            (float) $order->getRestaurant()->getLatitude(),
            (float) $order->getRestaurant()->getLongitude(),
        );
        if ($distance > (float) $offer->getSearchRadiusKm()) {
            throw new ConflictHttpException('This order is outside the current search radius.');
        }
        if ($checkActiveOrder && $this->orders->riderHasOtherActiveOrder($rider, $order->getId())) {
            throw new ConflictHttpException('Finish your current delivery before accepting another order.');
        }
        if ($checkCodLimit && Payment::METHOD_COD === $order->getPaymentMethod() && $this->hasReachedCashLimit($rider)) {
            throw new ConflictHttpException('Remit cash before accepting another COD order.');
        }
        if (!$adminOverride && Rider::AVAILABILITY_AVAILABLE !== $rider->getAvailabilityStatus()) {
            throw new ConflictHttpException('Go online before accepting an order.');
        }

        return $distance;
    }

    private function riderSkippedOrder(Order $order, Rider $rider): bool
    {
        return $this->entityManager->getRepository(RiderPoolDecline::class)->findOneBy([
            'order' => $order,
            'rider' => $rider,
            'action' => RiderPoolDecline::ACTION_SKIPPED,
        ]) instanceof RiderPoolDecline;
    }

    private function formatKm(float $kilometres): string
    {
        return number_format(round($kilometres, 2), 2, '.', '');
    }

    // ----------------------------------------------------------------------
    // Rider pool escalation: widens, incentivises, alerts, then cancels unaccepted orders.
    // ----------------------------------------------------------------------

    public function escalatePendingPoolOrders(?\DateTimeImmutable $now = null): int
    {
        $orders = $this->orders->findAwaitingRiderForEscalation();

        $processed = 0;
        foreach ($orders as $order) {
            if ($this->escalatePoolOrder($order, $now)) {
                ++$processed;
            }
        }

        return $processed;
    }

    /**
     * Advances every stage whose absolute elapsed threshold has already passed.
     * Returns true when at least one stage or the order status changed.
     */
    public function escalatePoolOrder(Order $order, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $startedAt = $order->getRiderSearchStartedAt();
        $offer = $order->getRiderPoolOffer();
        if (Order::STATUS_FINDING_RIDER !== $order->getStatus() || null !== $order->getRider() || null === $startedAt || null === $offer) {
            return false;
        }

        $elapsedSeconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
        $changed = false;
        while (Order::STATUS_FINDING_RIDER === $order->getStatus() && null === $order->getRider()) {
            $stage = $offer->getEscalationStage();
            if (RiderPoolOffer::STAGE_AUTO_CANCELLED === $stage) {
                return $this->autoCancelNoRider($order) || $changed;
            }

            [$next, $setting, $default] = $this->nextEscalationStage($stage);
            $thresholdSeconds = max(0.0, $this->settings->float($setting, $default)) * 60;
            if ($elapsedSeconds < $thresholdSeconds) {
                break;
            }

            if (RiderPoolOffer::STAGE_AUTO_CANCELLED === $next) {
                return $this->autoCancelNoRider($order) || $changed;
            }

            try {
                $order = $this->recordUpdate(
                    $order,
                    Order::ACTOR_SYSTEM,
                    'rider_pool_escalated',
                    sprintf('Rider pool escalated from %s to %s.', $stage, $next),
                    function (Order $locked, \DateTimeImmutable $changedAt) use ($stage, $next): void {
                        $lockedOffer = $locked->getRiderPoolOffer();
                        if (Order::STATUS_FINDING_RIDER !== $locked->getStatus()
                            || null !== $locked->getRider()
                            || null === $lockedOffer
                            || $stage !== $lockedOffer->getEscalationStage()) {
                            throw new ConflictHttpException('The rider pool offer changed while escalating.');
                        }

                        $lockedOffer
                            ->setEscalationStage($next)
                            ->setUpdatedAt($changedAt);
                        if (RiderPoolOffer::STAGE_WIDENED === $next) {
                            $radius = max(0.1, $this->settings->float('rider_search_widen_radius_km', 8.0));
                            $lockedOffer->setSearchRadiusKm(number_format($radius, 1, '.', ''));
                        } elseif (RiderPoolOffer::STAGE_INCENTIVIZED === $next) {
                            $incentive = max(0.0, $this->settings->float('rider_incentive_amount', 20.0));
                            $lockedOffer->setIncentiveAmount(number_format($incentive, 2, '.', ''));
                        }
                    },
                );
            } catch (ConflictHttpException) {
                return $changed;
            }
            $offer = $order->getRiderPoolOffer();
            if (null === $offer) {
                break;
            }
            $changed = true;
        }

        return $changed;
    }

    /** @return array{string, string, float} */
    private function nextEscalationStage(string $stage): array
    {
        return match ($stage) {
            RiderPoolOffer::STAGE_INITIAL => [RiderPoolOffer::STAGE_WIDENED, 'rider_search_widen_after_minutes', 2.0],
            RiderPoolOffer::STAGE_WIDENED => [RiderPoolOffer::STAGE_INCENTIVIZED, 'rider_incentive_after_minutes', 4.0],
            RiderPoolOffer::STAGE_INCENTIVIZED => [RiderPoolOffer::STAGE_ADMIN_ALERTED, 'admin_alert_after_minutes', 6.0],
            RiderPoolOffer::STAGE_ADMIN_ALERTED => [RiderPoolOffer::STAGE_CUSTOMER_NOTIFIED, 'customer_notify_after_minutes', 8.0],
            RiderPoolOffer::STAGE_CUSTOMER_NOTIFIED => [RiderPoolOffer::STAGE_AUTO_CANCELLED, 'auto_cancel_after_minutes', 15.0],
            RiderPoolOffer::STAGE_AUTO_CANCELLED => throw new \LogicException('An auto-cancelled offer has no next stage.'),
        };
    }

    private function autoCancelNoRider(Order $order): bool
    {
        try {
            $this->transition(
                $order,
                Order::STATUS_CANCELLED_NO_RIDER,
                Order::ACTOR_SYSTEM,
                'No rider accepted the order before the search deadline.',
                function (Order $locked, \DateTimeImmutable $now): void {
                    if (Order::STATUS_FINDING_RIDER !== $locked->getStatus() || null !== $locked->getRider()) {
                        throw new ConflictHttpException('The rider pool offer changed while escalating.');
                    }
                    $locked
                        ->setCancellationReason('No rider was found before the search deadline.')
                        ->setCancelledBy(Order::ACTOR_SYSTEM);
                    $locked->getRiderPoolOffer()?->setEscalationStage(RiderPoolOffer::STAGE_AUTO_CANCELLED)->setUpdatedAt($now);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund(
                            $locked->getPayment(),
                            Payment::ACTOR_SYSTEM,
                            'Order automatically cancelled because no rider was found.',
                            $now,
                        );
                    }
                },
            );
        } catch (ConflictHttpException|\DomainException) {
            return false;
        }

        return true;
    }

    // ----------------------------------------------------------------------
    // Rider pay: offer estimates and the final earning for a delivered order.
    // ----------------------------------------------------------------------

    /** @return array{base_pay: string, distance_pay: string, incentive_pay: string, total: string} */
    public function estimateRiderPay(float $pickupDistanceKm, float $deliveryDistanceKm, RiderPoolOffer $offer): array
    {
        $base = $this->settings->decimal('rider_base_pay', self::DEFAULT_BASE_PAY);
        $distance = $this->distancePay($pickupDistanceKm + $deliveryDistanceKm);
        $incentive = $this->nonNegativeAmount($offer->getIncentiveAmount());

        return [
            'base_pay' => $base,
            'distance_pay' => $distance,
            'incentive_pay' => $incentive,
            'total' => $this->sumAmounts($base, $distance, $incentive),
        ];
    }

    /** @return array{base_pay: string, distance_pay: string, waiting_pay: string, incentive_pay: string, tip_amount: string, total: string} */
    public function riderEarning(Order $order, float $restaurantWaitMinutes): array
    {
        $base = $this->settings->decimal('rider_base_pay', self::DEFAULT_BASE_PAY);
        $pickupDistanceKm = $order->getRiderPoolOffer()?->getAcceptedPickupDistanceKm();
        if (null === $pickupDistanceKm) {
            throw new \DomainException('The accepted pickup distance is missing for this order.');
        }
        $deliveryDistanceKm = DeliveryZoneRepository::distanceKm(
            (float) $order->getRestaurant()->getLatitude(),
            (float) $order->getRestaurant()->getLongitude(),
            (float) $order->getCustomerAddress()->getLatitude(),
            (float) $order->getCustomerAddress()->getLongitude(),
        );
        $distance = $this->distancePay((float) $pickupDistanceKm + $deliveryDistanceKm);
        $threshold = $this->settings->decimal('rider_waiting_compensation_threshold_minutes', '5', 4);
        $waiting = -1 !== bccomp(sprintf('%.4F', $restaurantWaitMinutes), $threshold, 4)
            ? $this->settings->decimal('rider_waiting_compensation_amount', '10.00')
            : '0.00';
        $incentive = $this->nonNegativeAmount($order->getRiderPoolOffer()?->getIncentiveAmount() ?? '0');
        $tip = $this->nonNegativeAmount($order->getTipAmount());

        return [
            'base_pay' => $base, 'distance_pay' => $distance,
            'waiting_pay' => $waiting, 'incentive_pay' => $incentive,
            'tip_amount' => $tip, 'total' => $this->sumAmounts($base, $distance, $waiting, $incentive, $tip),
        ];
    }

    private function distancePay(float $kilometres): string
    {
        $distance = sprintf('%.4F', max(0.0, $kilometres));
        $perKm = $this->settings->decimal('rider_distance_pay_per_km', self::DEFAULT_DISTANCE_RATE_PER_KM);

        return bcround(bcmul($distance, $perKm, 6), 2);
    }

    private function nonNegativeAmount(string $amount): string
    {
        return -1 === bccomp($amount, '0', 2) ? '0.00' : bcround($amount, 2);
    }

    private function sumAmounts(string ...$amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }
}
