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
use App\Exception\CheckoutValidationException;
use App\Event\OrderStatusChangedEvent;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class CheckoutService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformSettingService $settings,
        private DeliveryZoneService $deliveryZones,
        private EventDispatcherInterface $eventDispatcher,
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
            throw new CheckoutValidationException('checkout_token must be a valid UUID.');
        }

        $existing = $this->entityManager->getRepository(Order::class)->findOneBy(['checkoutToken' => $token]);
        if ($existing instanceof Order) {
            if ($existing->getCustomer() !== $customer) {
                throw new CheckoutValidationException('This checkout token is already in use.', 409);
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
                throw new CheckoutValidationException('Customer profile not found.', 404);
            }
            $existing = $this->entityManager->getRepository(Order::class)->findOneBy(['checkoutToken' => $token]);
            if ($existing instanceof Order) {
                if ($existing->getCustomer() !== $lockedCustomer) {
                    throw new CheckoutValidationException('This checkout token is already in use.', 409);
                }

                return new CheckoutResult($existing, false);
            }

            $now = new \DateTimeImmutable();
            $restaurant = $this->restaurant($input['restaurant_id'] ?? null);
            $address = $this->address($input['customer_address_id'] ?? null, $lockedCustomer);
            $paymentMethod = $this->paymentMethod($input['payment_method'] ?? null);
            $tipCents = $this->nonNegativeMoneyToCents($input['tip_amount'] ?? 0, 'tip_amount');
            $notes = $this->optionalText($input['customer_notes'] ?? null, 2000, 'customer_notes');

            $this->assertRestaurantCanAcceptOrders($restaurant, $now);
            if (!$this->deliveryZones->isCovered((float) $address->getLatitude(), (float) $address->getLongitude())) {
                throw new CheckoutValidationException('The selected address is outside the active delivery zones.');
            }

            $selections = $input['items'] ?? null;
            if (!is_array($selections) || [] === $selections) {
                throw new CheckoutValidationException('At least one cart item is required.');
            }

            [$itemRows, $subtotalCents] = $this->priceItems($selections, $restaurant, $now);
            if ($subtotalCents < $this->moneyToCents($restaurant->getMinOrderAmount())) {
                throw new CheckoutValidationException('The order subtotal does not meet the restaurant minimum.');
            }

            $distanceKm = DeliveryZoneService::distanceKm(
                (float) $restaurant->getLatitude(),
                (float) $restaurant->getLongitude(),
                (float) $address->getLatitude(),
                (float) $address->getLongitude(),
            );
            $deliveryFeeCents = (int) round(($this->settings->float('delivery_base_fee', 35.0)
                + $this->settings->float('delivery_fee_per_km', 10.0) * $distanceKm) * 100);
            $serviceFeeCents = (int) round(max(0.0, $this->settings->float('service_fee', 0.0)) * 100);

            $voucher = $this->lockedVoucher($input['voucher_code'] ?? null);
            $discountCents = 0;
            if ($voucher instanceof Voucher) {
                $discountCents = $this->voucherDiscount(
                    $voucher,
                    $lockedCustomer,
                    $restaurant,
                    $subtotalCents,
                    $deliveryFeeCents,
                    $now,
                );
            }

            $totalCents = max(0, $subtotalCents + $deliveryFeeCents + $serviceFeeCents + $tipCents - $discountCents);
            // Commission is based on food subtotal. Voucher funding ownership is not yet modelled.
            $commissionCents = (int) round($subtotalCents * ((float) $restaurant->getCommissionRate() / 100));
            $order = (new Order())
                ->setOrderNumber($this->newOrderNumber())
                ->setCheckoutToken($token)
                ->setCustomer($lockedCustomer)
                ->setRestaurant($restaurant)
                ->setCustomerAddress($address)
                ->setStatus(OrderStatus::PLACED)
                ->setSubtotal($this->decimal($subtotalCents))
                ->setDeliveryFee($this->decimal($deliveryFeeCents))
                ->setServiceFee($this->decimal($serviceFeeCents))
                ->setDiscountAmount($this->decimal($discountCents))
                ->setTipAmount($this->decimal($tipCents))
                ->setTotalAmount($this->decimal($totalCents))
                ->setCommissionAmount($this->decimal($commissionCents))
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
                    ->setUnitPrice($this->decimal($row['unit_price_cents']))
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
                ->setAmount($this->decimal($totalCents))
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
                    ->setDiscountApplied($this->decimal($discountCents))
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now);
                $order->setVoucherRedemption($redemption);
                $this->entityManager->persist($redemption);
            }

            $this->entityManager->flush();

            return new CheckoutResult($order, true);
        });

        if ($result->created) {
            $this->eventDispatcher->dispatch(new OrderStatusChangedEvent(
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

    private function restaurant(mixed $id): Restaurant
    {
        if ((!is_int($id) && !is_string($id)) || '' === (string) $id) {
            throw new CheckoutValidationException('restaurant_id is required.');
        }
        $restaurant = $this->entityManager->find(Restaurant::class, (string) $id);
        if (!$restaurant instanceof Restaurant) {
            throw new CheckoutValidationException('Restaurant not found.', 404);
        }

        return $restaurant;
    }

    private function address(mixed $id, Customer $customer): CustomerAddress
    {
        if ((!is_int($id) && !is_string($id)) || '' === (string) $id) {
            throw new CheckoutValidationException('customer_address_id is required.');
        }
        $address = $this->entityManager->find(CustomerAddress::class, (string) $id);
        if (!$address instanceof CustomerAddress || $address->getCustomer() !== $customer) {
            throw new CheckoutValidationException('Delivery address not found.', 404);
        }

        return $address;
    }

    private function paymentMethod(mixed $value): PaymentMethod
    {
        $method = is_string($value) ? PaymentMethod::tryFrom($value) : null;
        if (!$method instanceof PaymentMethod) {
            throw new CheckoutValidationException('payment_method must be cod, gcash, or card.');
        }

        return $method;
    }

    private function assertRestaurantCanAcceptOrders(Restaurant $restaurant, \DateTimeImmutable $now): void
    {
        if (ApprovalStatus::APPROVED !== $restaurant->getApprovalStatus()) {
            throw new CheckoutValidationException('This restaurant is not approved for orders.');
        }
        if (RestaurantOperatingStatus::OPEN !== $restaurant->getOperatingStatus()) {
            throw new CheckoutValidationException('This restaurant is currently closed.');
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

        throw new CheckoutValidationException('This restaurant is outside its operating hours.');
    }

    /**
     * @param array<mixed> $selections
     * @return array{0: list<array{item: MenuItem, variant: ?MenuItemVariant, addons: list<MenuItemAddon>, quantity: int, unit_price_cents: int, instructions: ?string}>, 1: int}
     */
    private function priceItems(array $selections, Restaurant $restaurant, \DateTimeImmutable $now): array
    {
        if (count($selections) > 100) {
            throw new CheckoutValidationException('A checkout may contain at most 100 item rows.');
        }
        $rows = [];
        $subtotalCents = 0;
        foreach ($selections as $selection) {
            if (!is_array($selection)) {
                throw new CheckoutValidationException('Each cart item must be an object.');
            }
            $item = $this->entityManager->find(MenuItem::class, (string) ($selection['menu_item_id'] ?? ''));
            if (!$item instanceof MenuItem || $item->getMenuCategory()->getRestaurant() !== $restaurant) {
                throw new CheckoutValidationException('A selected menu item does not belong to this restaurant.');
            }
            if (!$item->isAvailable() || !$this->isWithinTimeWindow($item->getAvailableFrom(), $item->getAvailableUntil(), $now)) {
                throw new CheckoutValidationException(sprintf('%s is not currently available.', $item->getName()));
            }
            $quantity = filter_var($selection['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (false === $quantity || $quantity < 1 || $quantity > 99) {
                throw new CheckoutValidationException('Each item quantity must be between 1 and 99.');
            }

            $variant = null;
            if (isset($selection['variant_id']) && null !== $selection['variant_id'] && '' !== $selection['variant_id']) {
                $variant = $this->entityManager->find(MenuItemVariant::class, (string) $selection['variant_id']);
                if (!$variant instanceof MenuItemVariant || $variant->getMenuItem() !== $item) {
                    throw new CheckoutValidationException('A selected variant does not belong to its menu item.');
                }
            }

            $addons = [];
            $addonIds = $selection['addon_ids'] ?? [];
            if (!is_array($addonIds)) {
                throw new CheckoutValidationException('addon_ids must be an array.');
            }
            foreach (array_unique(array_map('strval', $addonIds)) as $addonId) {
                $addon = $this->entityManager->find(MenuItemAddon::class, $addonId);
                if (!$addon instanceof MenuItemAddon || $addon->getMenuItem() !== $item || !$addon->isAvailable()) {
                    throw new CheckoutValidationException('A selected add-on is unavailable or does not belong to its menu item.');
                }
                $addons[] = $addon;
            }

            $unitPriceCents = $this->moneyToCents($item->getBasePrice())
                + ($variant instanceof MenuItemVariant ? $this->moneyToCents($variant->getPriceDelta()) : 0);
            $addonsCents = array_sum(array_map(fn (MenuItemAddon $addon): int => $this->moneyToCents($addon->getPrice()), $addons));
            if ($unitPriceCents < 0) {
                throw new CheckoutValidationException('A selected variant results in an invalid item price.');
            }
            $subtotalCents += ($unitPriceCents + $addonsCents) * $quantity;
            $rows[] = [
                'item' => $item,
                'variant' => $variant,
                'addons' => $addons,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'instructions' => $this->optionalText($selection['special_instructions'] ?? null, 1000, 'special_instructions'),
            ];
        }

        return [$rows, $subtotalCents];
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

    private function lockedVoucher(mixed $code): ?Voucher
    {
        $code = strtoupper(trim(is_string($code) ? $code : ''));
        if ('' === $code) {
            return null;
        }
        $voucher = $this->entityManager->getRepository(Voucher::class)->findOneBy(['code' => $code]);
        if (!$voucher instanceof Voucher) {
            throw new CheckoutValidationException('Voucher code is invalid.');
        }
        $this->entityManager->lock($voucher, LockMode::PESSIMISTIC_WRITE);

        return $voucher;
    }

    private function voucherDiscount(
        Voucher $voucher,
        Customer $customer,
        Restaurant $restaurant,
        int $subtotalCents,
        int $deliveryFeeCents,
        \DateTimeImmutable $now,
    ): int {
        if (!$voucher->isActive() || (null !== $voucher->getStartsAt() && $now < $voucher->getStartsAt()) || (null !== $voucher->getEndsAt() && $now > $voucher->getEndsAt())) {
            throw new CheckoutValidationException('Voucher is not active.');
        }
        if ($subtotalCents < $this->moneyToCents($voucher->getMinOrderAmount())) {
            throw new CheckoutValidationException('The order does not meet the voucher minimum.');
        }
        if (VoucherScope::RESTAURANT === $voucher->getScope() && $voucher->getRestaurant() !== $restaurant) {
            throw new CheckoutValidationException('Voucher does not apply to this restaurant.');
        }

        $repository = $this->entityManager->getRepository(VoucherRedemption::class);
        $totalUses = (int) $repository->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.voucher = :voucher')
            ->setParameter('voucher', $voucher)
            ->getQuery()->getSingleScalarResult();
        if (null !== $voucher->getUsageLimitTotal() && $totalUses >= $voucher->getUsageLimitTotal()) {
            throw new CheckoutValidationException('Voucher usage limit has been reached.');
        }
        $customerUses = (int) $repository->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.voucher = :voucher')
            ->andWhere('redemption.customer = :customer')
            ->setParameter('voucher', $voucher)
            ->setParameter('customer', $customer)
            ->getQuery()->getSingleScalarResult();
        if ($customerUses >= $voucher->getUsageLimitPerCustomer()) {
            throw new CheckoutValidationException('You have already reached this voucher usage limit.');
        }

        $value = max(0.0, (float) ($voucher->getValue() ?? '0'));

        return match ($voucher->getType()) {
            VoucherType::PERCENTAGE => min($subtotalCents, (int) round($subtotalCents * min(100.0, $value) / 100)),
            VoucherType::FIXED => min($subtotalCents, (int) round($value * 100)),
            VoucherType::FREE_DELIVERY => $deliveryFeeCents,
        };
    }

    private function nonNegativeMoneyToCents(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric((string) $value) || (float) $value < 0) {
            throw new CheckoutValidationException(sprintf('%s must be a non-negative amount.', $field));
        }

        return (int) round((float) $value * 100);
    }

    private function moneyToCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function optionalText(mixed $value, int $maxLength, string $field): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new CheckoutValidationException(sprintf('%s must be at most %d characters.', $field, $maxLength));
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
