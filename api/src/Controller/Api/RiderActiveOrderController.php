<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\Rider;
use App\Entity\RiderEarning;
use App\Enum\OrderActor;
use App\Enum\OrderReportAgainst;
use App\Enum\OrderReportStatus;
use App\Enum\OrderReportType;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Enum\PaymentMethod;
use App\Enum\RiderAvailabilityStatus;
use App\Exception\InvalidOrderTransitionException;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use App\Service\PlatformSettingService;
use App\Service\RiderPayCalculator;
use App\Service\UploadStorage;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider/active-order', name: 'api_rider_active_order_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderActiveOrderController extends AbstractRiderController
{
    private const PROOF_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
        private readonly RiderPayCalculator $payCalculator,
        private readonly PlatformSettingService $settings,
        private readonly UploadStorage $uploads,
    ) {
        parent::__construct($entityManager);
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $order = $this->activeOrder(false);
        if (null === $order) {
            return $this->json(['message' => 'No active order.', 'order' => null], 404);
        }

        return $this->json(['order' => $this->orderData($order)]);
    }

    #[Route('/arrived-at-restaurant', name: 'arrived_restaurant', methods: ['POST'])]
    public function arrivedAtRestaurant(): JsonResponse
    {
        return $this->advance(OrderStatus::RIDER_ASSIGNED, OrderStatus::AT_RESTAURANT, 'Rider arrived at the restaurant.',
            static fn (Order $order, \DateTimeImmutable $now) => $order->setRiderArrivedRestaurantAt($now));
    }

    #[Route('/confirm-pickup', name: 'confirm_pickup', methods: ['POST'])]
    public function confirmPickup(Request $request): JsonResponse
    {
        $order = $this->activeOrder();
        if (OrderStatus::AT_RESTAURANT !== $order->getStatus()) {
            return $this->invalidStatus($order, OrderStatus::AT_RESTAURANT);
        }
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $code = trim((string) ($data['pickup_code'] ?? ''));
        if ('' === $code || !hash_equals((string) $order->getPickupCode(), $code)) {
            return $this->json(['message' => 'The pickup code is invalid.'], 422);
        }

        return $this->transition($order, OrderStatus::PICKED_UP, 'Restaurant handoff confirmed.',
            static fn (Order $locked, \DateTimeImmutable $now) => $locked->setPickedUpAt($now));
    }

    #[Route('/start-delivery', name: 'start_delivery', methods: ['POST'])]
    public function startDelivery(): JsonResponse
    {
        return $this->advance(OrderStatus::PICKED_UP, OrderStatus::ON_THE_WAY, 'Rider started the delivery.');
    }

    #[Route('/arrived-at-customer', name: 'arrived_customer', methods: ['POST'])]
    public function arrivedAtCustomer(): JsonResponse
    {
        return $this->advance(OrderStatus::ON_THE_WAY, OrderStatus::ARRIVED, 'Rider arrived at the customer.');
    }

    #[Route('/confirm-delivery', name: 'confirm_delivery', methods: ['POST'])]
    public function confirmDelivery(Request $request): JsonResponse
    {
        $order = $this->activeOrder();
        if (OrderStatus::ARRIVED !== $order->getStatus()) {
            return $this->invalidStatus($order, OrderStatus::ARRIVED);
        }
        $file = $request->files->get('proof');
        if (null === $file) {
            return $this->json(['message' => 'A proof-of-delivery image is required.', 'errors' => ['proof' => ['A proof-of-delivery image is required.']]], 422);
        }
        $cashCollected = filter_var($request->request->get('cashCollected', $request->request->get('cash_collected')), FILTER_VALIDATE_BOOL);
        if (PaymentMethod::COD === $order->getPaymentMethod() && true !== $cashCollected) {
            return $this->json(['message' => 'Confirm that COD cash was collected before completing delivery.'], 422);
        }
        if (PaymentMethod::COD === $order->getPaymentMethod() && null === $order->getPayment()) {
            return $this->json(['message' => 'The COD payment record is missing.'], 409);
        }
        if (null === $order->getRiderPoolOffer()?->getAcceptedPickupDistanceKm()) {
            return $this->json(['message' => 'The accepted pickup distance is missing; contact support before completing this delivery.'], 409);
        }
        try {
            $path = $this->uploads->store($file, 'orders/proof-of-delivery', self::PROOF_MIME_TYPES);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage(), 'errors' => ['proof' => [$exception->getMessage()]]], 422);
        }

        try {
            $order = $this->transitions->transition($order, OrderStatus::DELIVERED, OrderActor::RIDER, 'Delivery completed by rider.',
                function (Order $locked, \DateTimeImmutable $now) use ($path): void {
                    $rider = $this->entityManager->find(Rider::class, $locked->getRider()?->getId(), LockMode::PESSIMISTIC_WRITE);
                    if (!$rider instanceof Rider) { throw new \DomainException('Assigned rider not found.'); }
                    $locked->setProofOfDeliveryPath($path);
                    if (PaymentMethod::COD === $locked->getPaymentMethod() && null !== $locked->getPayment()) {
                        $this->payments->markPaid($locked->getPayment(), PaymentActor::RIDER, 'COD cash collected by rider.', $now);
                        $rider->setCashOnHand($this->money((float) $rider->getCashOnHand() + (float) $locked->getTotalAmount()));
                    }
                    $waitMinutes = 0.0;
                    if (null !== $locked->getRiderArrivedRestaurantAt() && null !== $locked->getPickedUpAt()) {
                        $waitMinutes = max(0.0, ($locked->getPickedUpAt()->getTimestamp() - $locked->getRiderArrivedRestaurantAt()->getTimestamp()) / 60);
                    }
                    $pay = $this->payCalculator->earning($locked, $waitMinutes);
                    if (null === $locked->getRiderEarning()) {
                        $earning = (new RiderEarning())->setRider($rider)->setOrder($locked)->setBasePay($pay['base_pay'])
                            ->setDistancePay($pay['distance_pay'])->setWaitingPay($pay['waiting_pay'])->setIncentivePay($pay['incentive_pay'])
                            ->setTipAmount($pay['tip_amount'])->setTotalEarned($pay['total'])->setCreatedAt($now)->setUpdatedAt($now);
                        $locked->setRiderEarning($earning);
                        $this->entityManager->persist($earning);
                    }
                    $rider->setAvailabilityStatus(RiderAvailabilityStatus::AVAILABLE)->setUpdatedAt($now);
                });
        } catch (\Throwable $exception) {
            $this->uploads->delete($path);
            if ($exception instanceof InvalidOrderTransitionException) {
                return $this->json(['message' => $exception->getMessage()], 409);
            }
            throw $exception;
        }

        return $this->json(['message' => 'Delivery confirmed.', 'order' => $this->orderData($order), 'earning' => $this->earningData($order->getRiderEarning())]);
    }

    #[Route('/report-issue', name: 'report_issue', methods: ['POST'])]
    public function reportIssue(Request $request): JsonResponse
    {
        $order = $this->activeOrder();
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $type = OrderReportType::tryFrom((string) ($data['type'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        if (null === $type || '' === $description || mb_strlen($description) > 5000) {
            return $this->json(['message' => 'A valid type and description between 1 and 5000 characters are required.'], 422);
        }
        $against = match ($type) {
            OrderReportType::MISSING_ITEM, OrderReportType::WRONG_ITEM, OrderReportType::LATE_DELIVERY => OrderReportAgainst::RESTAURANT,
            OrderReportType::CUSTOMER_UNREACHABLE, OrderReportType::RUDE_BEHAVIOR => OrderReportAgainst::CUSTOMER,
            default => OrderReportAgainst::PLATFORM,
        };
        $now = new \DateTimeImmutable();
        $report = (new OrderReport())->setOrder($order)->setReportedBy($this->riderUser())->setAgainst($against)->setType($type)
            ->setDescription($description)->setStatus(OrderReportStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $this->json(['message' => 'Issue reported.', 'report' => ['id' => $report->getId(), 'type' => $type->value, 'against' => $against->value, 'status' => $report->getStatus()->value]], 201);
    }

    #[Route('/customer-unreachable', name: 'customer_unreachable', methods: ['POST'])]
    public function customerUnreachable(): JsonResponse
    {
        $order = $this->activeOrder();
        if (OrderStatus::ARRIVED !== $order->getStatus()) { return $this->invalidStatus($order, OrderStatus::ARRIVED); }
        $arrival = $this->entityManager->getRepository(OrderStatusHistory::class)->findOneBy(['order' => $order, 'status' => OrderStatus::ARRIVED->value], ['createdAt' => 'DESC', 'id' => 'DESC']);
        if (!$arrival instanceof OrderStatusHistory) {
            return $this->json(['message' => 'The customer-arrival timestamp is missing.'], 409);
        }
        $waitMinutes = max(0.0, $this->settings->float('rider_customer_unreachable_wait_minutes', 5.0));
        $eligibleAt = $arrival->getCreatedAt()->modify(sprintf('+%d seconds', (int) ceil($waitMinutes * 60)));
        if (new \DateTimeImmutable() < $eligibleAt) {
            return $this->json(['message' => 'Wait until the customer-unreachable timer finishes.', 'eligible_at' => $eligibleAt->format(\DateTimeInterface::ATOM)], 409);
        }

        return $this->failDelivery($order, 'Customer unreachable after the required wait.');
    }

    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Request $request): JsonResponse
    {
        $order = $this->activeOrder();
        if (!in_array($order->getStatus(), [OrderStatus::RIDER_ASSIGNED, OrderStatus::AT_RESTAURANT], true)) {
            return $this->json(['message' => 'A rider can only cancel before pickup.'], 409);
        }
        $data = $this->body($request);
        if ($data instanceof JsonResponse) { return $data; }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ('' === $reason || mb_strlen($reason) > 2000) {
            return $this->json(['message' => 'reason must be between 1 and 2000 characters.'], 422);
        }

        return $this->failDelivery($order, $reason);
    }

    private function advance(OrderStatus $from, OrderStatus $to, string $note, ?callable $changes = null): JsonResponse
    {
        $order = $this->activeOrder();
        if ($from !== $order->getStatus()) { return $this->invalidStatus($order, $from); }
        return $this->transition($order, $to, $note, $changes);
    }

    private function transition(Order $order, OrderStatus $to, string $note, ?callable $changes = null): JsonResponse
    {
        try { $order = $this->transitions->transition($order, $to, OrderActor::RIDER, $note, $changes); }
        catch (InvalidOrderTransitionException $exception) { return $this->json(['message' => $exception->getMessage()], 409); }
        return $this->json(['order' => $this->orderData($order)]);
    }

    private function failDelivery(Order $order, string $reason): JsonResponse
    {
        try {
            $order = $this->transitions->transition($order, OrderStatus::FAILED_DELIVERY, OrderActor::RIDER, $reason,
                static function (Order $locked, \DateTimeImmutable $now) use ($reason): void {
                    $locked->setCancellationReason($reason)->setCancelledBy(OrderActor::RIDER);
                    $locked->getRider()?->setAvailabilityStatus(RiderAvailabilityStatus::AVAILABLE)->setUpdatedAt($now);
                });
        } catch (InvalidOrderTransitionException $exception) { return $this->json(['message' => $exception->getMessage()], 409); }
        return $this->json(['message' => 'Delivery marked failed.', 'order' => $this->orderData($order)]);
    }

    private function invalidStatus(Order $order, OrderStatus $expected): JsonResponse
    {
        return $this->json(['message' => sprintf('Order must be %s for this action; current status is %s.', $expected->value, $order->getStatus()->value)], 409);
    }

    /** @return array<string, mixed> */
    private function orderData(Order $order): array
    {
        return ['id' => $order->getId(), 'order_number' => $order->getOrderNumber(), 'status' => $order->getStatus()->value,
            'restaurant' => ['name' => $order->getRestaurant()->getName(), 'address' => $order->getRestaurant()->getAddress(), 'latitude' => $order->getRestaurant()->getLatitude(), 'longitude' => $order->getRestaurant()->getLongitude()],
            'delivery_address' => ['address_line' => $order->getCustomerAddress()->getAddressLine(), 'landmark' => $order->getCustomerAddress()->getLandmark(), 'instructions' => $order->getCustomerAddress()->getDeliveryInstructions(), 'latitude' => $order->getCustomerAddress()->getLatitude(), 'longitude' => $order->getCustomerAddress()->getLongitude()],
            'customer' => ['name' => $order->getCustomer()->getUser()->getName(), 'phone' => $order->getCustomer()->getUser()->getPhone()],
            'items' => array_map(static fn (OrderItem $item): array => ['name' => $item->getMenuItem()->getName(), 'quantity' => $item->getQuantity(), 'special_instructions' => $item->getSpecialInstructions()], $order->getItems()->toArray()),
            'customer_notes' => $order->getCustomerNotes(), 'payment_method' => $order->getPaymentMethod()->value,
            'cash_to_collect' => PaymentMethod::COD === $order->getPaymentMethod() ? $order->getTotalAmount() : null,
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM), 'pickup_code_required' => OrderStatus::AT_RESTAURANT === $order->getStatus(),
            'proof_of_delivery_path' => $order->getProofOfDeliveryPath(), 'delivered_at' => $order->getDeliveredAt()?->format(\DateTimeInterface::ATOM)];
    }

    /** @return array<string, mixed>|null */
    private function earningData(?RiderEarning $earning): ?array
    {
        return null === $earning ? null : ['id' => $earning->getId(), 'base_pay' => $earning->getBasePay(), 'distance_pay' => $earning->getDistancePay(), 'waiting_pay' => $earning->getWaitingPay(), 'incentive_pay' => $earning->getIncentivePay(), 'tip_amount' => $earning->getTipAmount(), 'total_earned' => $earning->getTotalEarned()];
    }

    private function money(float $amount): string { return number_format(round($amount, 2), 2, '.', ''); }
}
