<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CheckoutResult;
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
use App\Entity\Voucher;
use App\Entity\VoucherRedemption;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\VoucherScope;
use App\Enum\VoucherType;
use App\Message\OrderStatusChanged;
use App\Repository\VoucherRedemptionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CheckoutService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformSettingService $settings,
        private DeliveryZoneService $deliveryZones,
        private MessageBusInterface $bus,
        private VoucherRedemptionRepository $voucherRedemptions,
    ) {
    }

    /**
     * The caller must reuse checkout_token when retrying the same submission. The database
     * unique constraint is the final guard; this early lookup makes normal retries idempotent.
     *
     * @param array<string, mixed> $input
     */
    public function checkout(Customer $customer, array $input): CheckoutResult
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

            return new CheckoutResult($existing, false);
        }

        /** @var CheckoutResult $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($customer, $input, $token): CheckoutResult {
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

                return new CheckoutResult($existing, false);
            }

            $now = new \DateTimeImmutable();
            $quote = $this->quote($lockedCustomer, $input, $now, true);
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
                ->setStatus(OrderStatus::PLACED)
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
                ->setStatus(OrderStatus::PLACED->value)
                ->setChangedBy(OrderActor::CUSTOMER)
                ->setNote('Order placed by customer.')
                ->setCreatedAt($now);
            $order->addStatusHistory($orderHistory);
            $this->entityManager->persist($orderHistory);

            $paymentStatus = PaymentMethod::COD === $paymentMethod ? PaymentStatus::PENDING : PaymentStatus::PAID;
            $payment = (new Payment())
                ->setOrder($order)
                ->setMethod($paymentMethod)
                ->setStatus($paymentStatus)
                ->setAmount($quote['total'])
                ->setPaidAt(PaymentStatus::PAID === $paymentStatus ? $now : null)
                ->setTransactionReference(PaymentStatus::PAID === $paymentStatus ? 'SIMULATED-'.strtoupper(bin2hex(random_bytes(8))) : null)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $order->setPayment($payment);
            $this->entityManager->persist($payment);
            $paymentHistory = (new PaymentStatusHistory())
                ->setPayment($payment)
                ->setFromStatus(null)
                ->setToStatus($paymentStatus)
                ->setChangedBy(PaymentActor::SYSTEM)
                ->setNote(PaymentStatus::PAID === $paymentStatus ? 'Simulated payment completed.' : 'COD payment awaiting collection.')
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

            return new CheckoutResult($order, true);
        });

        if ($result->created) {
            $this->bus->dispatch(new OrderStatusChanged(
                orderId: (string) $result->order->getId(),
                previousStatus: null,
                currentStatus: OrderStatus::PLACED,
                changedBy: OrderActor::CUSTOMER,
                note: 'Order placed by customer.',
                changedAt: $result->order->getPlacedAt(),
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
    public function preview(Customer $customer, array $input): array
    {
        $quote = $this->quote($customer, $input, new \DateTimeImmutable(), false);

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
            'payment_method' => $quote['payment_method']->value,
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
     *     payment_method: PaymentMethod,
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
    private function quote(Customer $customer, array $input, \DateTimeImmutable $now, bool $lockVoucher): array
    {
        $restaurant = $this->restaurant($input['restaurant_id'] ?? null);
        $address = $this->address($input['customer_address_id'] ?? null, $customer);
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

        $distanceKm = DeliveryZoneService::distanceKm(
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

        $voucher = $this->voucher($input['voucher_code'] ?? null, $lockVoucher);
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

    private function restaurant(mixed $id): Restaurant
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

    private function address(mixed $id, Customer $customer): CustomerAddress
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

    private function paymentMethod(mixed $value): PaymentMethod
    {
        $method = is_string($value) ? PaymentMethod::tryFrom($value) : null;
        if (!$method instanceof PaymentMethod) {
            throw new UnprocessableEntityHttpException('payment_method must be cod, gcash, or card.');
        }

        return $method;
    }

    private function assertRestaurantCanAcceptOrders(Restaurant $restaurant, \DateTimeImmutable $now): void
    {
        if (ApprovalStatus::APPROVED !== $restaurant->getApprovalStatus()) {
            throw new UnprocessableEntityHttpException('This restaurant is not approved for orders.');
        }
        if (RestaurantOperatingStatus::OPEN !== $restaurant->getOperatingStatus()) {
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

    private function voucher(mixed $code, bool $lock): ?Voucher
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
        if (VoucherScope::RESTAURANT === $voucher->getScope() && $voucher->getRestaurant() !== $restaurant) {
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
            VoucherType::PERCENTAGE => bcround(bcdiv(bcmul($subtotal, 1 === bccomp($value, '100', 2) ? '100' : $value, 6), '100', 6), 2),
            VoucherType::FIXED => bcround($value, 2),
            VoucherType::FREE_DELIVERY => $deliveryFee,
        };

        // A voucher never discounts more than the food subtotal (free delivery is bounded by the fee itself).
        return VoucherType::FREE_DELIVERY !== $voucher->getType() && 1 === bccomp($discount, $subtotal, 2) ? $subtotal : $discount;
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
}
