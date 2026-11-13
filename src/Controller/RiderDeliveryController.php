<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\Rider;
use App\Entity\RiderEarning;
use App\Entity\User;
use App\Form\DeliveryProofFormType;
use App\Form\OrderRejectFormType;
use App\Form\PickupCodeFormType;
use App\Form\RiderIssueFormType;
use App\Repository\OrderRepository;
use App\Repository\PlatformSettingRepository;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The delivery in progress, one step at a time: arrived at the restaurant, pickup code,
 * on the way, arrived, delivered with proof (and cash for COD). Before pickup the rider may
 * cancel; at the door, after the wait, the customer can be marked unreachable.
 */
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
#[Route('/rider/delivery')]
final class RiderDeliveryController extends AbstractController
{
    /** The single-button steps: action => [from status, to status, note]. */
    private const STEPS = [
        'arrived-restaurant' => [Order::STATUS_RIDER_ASSIGNED, Order::STATUS_AT_RESTAURANT, 'Rider arrived at the restaurant.'],
        'start' => [Order::STATUS_PICKED_UP, Order::STATUS_ON_THE_WAY, 'Rider started the delivery.'],
        'arrived-customer' => [Order::STATUS_ON_THE_WAY, Order::STATUS_ARRIVED, 'Rider arrived at the customer.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRepository $orders,
        private readonly OrderTransitionService $transitions,
        #[Autowire('%app.private_upload_dir%')]
        private readonly string $privateUploadDir,
    ) {
    }

    #[Route('', name: 'app_rider_delivery', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, PlatformSettingRepository $settings): Response
    {
        $order = $this->orders->findCurrentForRider($this->rider($user));
        if (null === $order) {
            $this->addFlash('error', 'You have no delivery in progress.');

            return $this->redirectToRoute('app_rider_dashboard');
        }

        return $this->renderDelivery($order, $settings);
    }

    #[Route('/step/{action}', name: 'app_rider_delivery_step', methods: ['POST'], requirements: ['action' => 'arrived-restaurant|start|arrived-customer'])]
    public function step(string $action, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->current($user);
        if (!$this->isCsrfTokenValid('delivery'.$order->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        [$from, $to, $note] = self::STEPS[$action];
        if ($from !== $order->getStatus()) {
            return $this->back(sprintf('This step needs the order to be %s.', str_replace('_', ' ', $from)));
        }
        $changes = 'arrived-restaurant' === $action
            ? static fn (Order $locked, \DateTimeImmutable $now) => $locked->setRiderArrivedRestaurantAt($now)
            : null;

        return $this->move($order, $to, $note, $changes);
    }

    #[Route('/pickup', name: 'app_rider_delivery_pickup', methods: ['POST'])]
    public function pickup(Request $request, #[CurrentUser] User $user, PlatformSettingRepository $settings): Response
    {
        $order = $this->current($user);
        if (Order::STATUS_AT_RESTAURANT !== $order->getStatus()) {
            return $this->back('Confirm the pickup at the restaurant.');
        }
        $form = $this->createForm(PickupCodeFormType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDelivery($order, $settings, pickup: $form);
        }
        if (!hash_equals((string) $order->getPickupCode(), trim((string) $form->get('pickupCode')->getData()))) {
            return $this->back('The pickup code is not right. Ask the restaurant again.');
        }

        return $this->move($order, Order::STATUS_PICKED_UP, 'Restaurant handoff confirmed.',
            static fn (Order $locked, \DateTimeImmutable $now) => $locked->setPickedUpAt($now));
    }

    #[Route('/complete', name: 'app_rider_delivery_complete', methods: ['POST'])]
    public function complete(Request $request, #[CurrentUser] User $user, PaymentTransitionService $payments, PlatformSettingRepository $settings): Response
    {
        $order = $this->current($user);
        if (Order::STATUS_ARRIVED !== $order->getStatus()) {
            return $this->back('Complete the delivery once you have arrived.');
        }
        $form = $this->proofForm($order)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDelivery($order, $settings, proof: $form);
        }
        if (Payment::METHOD_COD === $order->getPaymentMethod() && null === $order->getPayment()) {
            return $this->back('The cash payment record is missing; contact support.');
        }
        if (null === $order->getRiderPoolOffer()?->getAcceptedPickupDistanceKm()) {
            return $this->back('The accepted pickup distance is missing; contact support before completing this delivery.');
        }

        /** @var UploadedFile $proof */
        $proof = $form->get('proof')->getData();
        $filename = bin2hex(random_bytes(20)).'.'.($proof->guessExtension() ?: 'bin');
        $proof->move($this->privateUploadDir.'/orders/proof-of-delivery', $filename);
        $path = 'orders/proof-of-delivery/'.$filename;

        try {
            $this->transitions->transition($order, Order::STATUS_DELIVERED, Order::ACTOR_RIDER, 'Delivery completed by rider.',
                function (Order $locked, \DateTimeImmutable $now) use ($path, $payments): void {
                    $rider = $this->entityManager->find(Rider::class, $locked->getRider()?->getId(), LockMode::PESSIMISTIC_WRITE)
                        ?? throw new \DomainException('Assigned rider not found.');
                    $locked->setProofOfDeliveryPath($path);
                    if (Payment::METHOD_COD === $locked->getPaymentMethod() && null !== $locked->getPayment()) {
                        $payments->markPaid($locked->getPayment(), Payment::ACTOR_RIDER, 'COD cash collected by rider.', $now);
                        $rider->setCashOnHand(bcadd($rider->getCashOnHand(), $locked->getTotalAmount(), 2));
                    }
                    $waitMinutes = 0.0;
                    if (null !== $locked->getRiderArrivedRestaurantAt() && null !== $locked->getPickedUpAt()) {
                        $waitMinutes = max(0.0, ($locked->getPickedUpAt()->getTimestamp() - $locked->getRiderArrivedRestaurantAt()->getTimestamp()) / 60);
                    }
                    if (null === $locked->getRiderEarning()) {
                        $pay = $this->transitions->riderEarning($locked, $waitMinutes);
                        $earning = (new RiderEarning())->setRider($rider)->setOrder($locked)->setBasePay($pay['base_pay'])
                            ->setDistancePay($pay['distance_pay'])->setWaitingPay($pay['waiting_pay'])->setIncentivePay($pay['incentive_pay'])
                            ->setTipAmount($pay['tip_amount'])->setTotalEarned($pay['total'])->setCreatedAt($now)->setUpdatedAt($now);
                        $locked->setRiderEarning($earning);
                        $this->entityManager->persist($earning);
                    }
                    $rider->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE)->setUpdatedAt($now);
                });
        } catch (\Throwable $exception) {
            @unlink($this->privateUploadDir.'/'.$path);
            if ($exception instanceof \DomainException) {
                return $this->back($exception->getMessage());
            }
            throw $exception;
        }
        $this->addFlash('success', sprintf('Delivered. You earned PHP %s.', $order->getRiderEarning()?->getTotalEarned() ?? '0.00'));

        return $this->redirectToRoute('app_rider_dashboard');
    }

    #[Route('/unreachable', name: 'app_rider_delivery_unreachable', methods: ['POST'])]
    public function unreachable(Request $request, #[CurrentUser] User $user, PlatformSettingRepository $settings, PaymentTransitionService $payments): Response
    {
        $order = $this->current($user);
        if (!$this->isCsrfTokenValid('delivery'.$order->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $eligibleAt = $this->unreachableFrom($order, $settings);
        if (null === $eligibleAt) {
            return $this->back('You can mark the customer unreachable once you have arrived.');
        }
        if (new \DateTimeImmutable() < $eligibleAt) {
            return $this->back(sprintf('Please wait for the customer until %s.', $eligibleAt->format('g:i:s A')));
        }

        return $this->fail($order, 'Customer unreachable after the required wait.', $payments);
    }

    #[Route('/cancel', name: 'app_rider_delivery_cancel', methods: ['POST'])]
    public function cancel(Request $request, #[CurrentUser] User $user, PlatformSettingRepository $settings, PaymentTransitionService $payments): Response
    {
        $order = $this->current($user);
        if (!in_array($order->getStatus(), [Order::STATUS_RIDER_ASSIGNED, Order::STATUS_AT_RESTAURANT], true)) {
            return $this->back('A rider can only cancel before pickup.');
        }
        $form = $this->cancelForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDelivery($order, $settings, cancel: $form);
        }

        return $this->fail($order, trim((string) $form->get('reason')->getData()), $payments);
    }

    #[Route('/issue', name: 'app_rider_delivery_issue', methods: ['POST'])]
    public function issue(Request $request, #[CurrentUser] User $user, PlatformSettingRepository $settings): Response
    {
        $order = $this->current($user);
        $form = $this->createForm(RiderIssueFormType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDelivery($order, $settings, issue: $form);
        }
        $type = $form->get('type')->getData();
        $against = match ($type) {
            OrderReport::TYPE_MISSING_ITEM, OrderReport::TYPE_WRONG_ITEM, OrderReport::TYPE_LATE_DELIVERY => OrderReport::AGAINST_RESTAURANT,
            OrderReport::TYPE_CUSTOMER_UNREACHABLE, OrderReport::TYPE_RUDE_BEHAVIOR => OrderReport::AGAINST_CUSTOMER,
            default => OrderReport::AGAINST_PLATFORM,
        };
        $now = new \DateTimeImmutable();
        $report = (new OrderReport())->setOrder($order)->setReportedBy($user)->setAgainst($against)->setType($type)
            ->setDescription(trim((string) $form->get('description')->getData()))->setStatus(OrderReport::STATUS_OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($report);
        $this->entityManager->flush();
        $this->addFlash('success', 'Issue reported. Support will follow up.');

        return $this->redirectToRoute('app_rider_delivery');
    }

    private function renderDelivery(
        Order $order,
        PlatformSettingRepository $settings,
        ?FormInterface $pickup = null,
        ?FormInterface $proof = null,
        ?FormInterface $cancel = null,
        ?FormInterface $issue = null,
    ): Response {
        $status = $order->getStatus();

        return $this->render('rider_delivery/index.html.twig', [
            'order' => $order,
            'cash_to_collect' => Payment::METHOD_COD === $order->getPaymentMethod() ? $order->getTotalAmount() : null,
            'step' => array_key_first(array_filter(self::STEPS, static fn (array $step): bool => $step[0] === $status)),
            'pickup_form' => Order::STATUS_AT_RESTAURANT === $status ? ($pickup ?? $this->createForm(PickupCodeFormType::class)) : null,
            'proof_form' => Order::STATUS_ARRIVED === $status ? ($proof ?? $this->proofForm($order)) : null,
            'cancel_form' => in_array($status, [Order::STATUS_RIDER_ASSIGNED, Order::STATUS_AT_RESTAURANT], true) ? ($cancel ?? $this->cancelForm()) : null,
            'issue_form' => $issue ?? $this->createForm(RiderIssueFormType::class),
            'unreachable_from' => $this->unreachableFrom($order, $settings),
        ], new Response(status: null !== ($pickup ?? $proof ?? $cancel ?? $issue) ? 422 : 200));
    }

    /** When the rider may give up on an unreachable customer, or null before arrival. */
    private function unreachableFrom(Order $order, PlatformSettingRepository $settings): ?\DateTimeImmutable
    {
        if (Order::STATUS_ARRIVED !== $order->getStatus()) {
            return null;
        }
        $arrival = $this->entityManager->getRepository(OrderStatusHistory::class)
            ->findOneBy(['order' => $order, 'status' => Order::STATUS_ARRIVED], ['createdAt' => 'DESC', 'id' => 'DESC']);
        if (null === $arrival) {
            return null;
        }
        $waitMinutes = max(0.0, $settings->float('rider_customer_unreachable_wait_minutes', 5.0));

        return $arrival->getCreatedAt()->modify(sprintf('+%d seconds', (int) ceil($waitMinutes * 60)));
    }

    private function proofForm(Order $order): FormInterface
    {
        return $this->createForm(DeliveryProofFormType::class, null, [
            'cash_amount' => Payment::METHOD_COD === $order->getPaymentMethod() ? $order->getTotalAmount() : null,
        ]);
    }

    private function cancelForm(): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('cancel_delivery', OrderRejectFormType::class);
    }

    private function move(Order $order, string $to, string $note, ?callable $changes = null): Response
    {
        try {
            $this->transitions->transition($order, $to, Order::ACTOR_RIDER, $note, $changes);
        } catch (\DomainException $exception) {
            return $this->back($exception->getMessage());
        }

        return $this->redirectToRoute('app_rider_delivery');
    }

    /**
     * Ends the order as a failed delivery. Cash on delivery can no longer be collected, so its
     * payment is closed as failed; a prepaid payment is left for an admin to refund or keep.
     */
    private function fail(Order $order, string $reason, PaymentTransitionService $payments): Response
    {
        try {
            $this->transitions->transition($order, Order::STATUS_FAILED_DELIVERY, Order::ACTOR_RIDER, $reason,
                static function (Order $locked, \DateTimeImmutable $now) use ($reason, $payments): void {
                    $locked->setCancellationReason($reason)->setCancelledBy(Order::ACTOR_RIDER);
                    $locked->getRider()?->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE)->setUpdatedAt($now);
                    if (Payment::METHOD_COD === $locked->getPaymentMethod() && null !== $locked->getPayment()) {
                        $payments->cancelOrRefund($locked->getPayment(), Payment::ACTOR_SYSTEM, 'Delivery failed: '.$reason, $now);
                    }
                });
        } catch (\DomainException $exception) {
            return $this->back($exception->getMessage());
        }
        $this->addFlash('success', 'The delivery was marked failed.');

        return $this->redirectToRoute('app_rider_dashboard');
    }

    private function back(string $error): Response
    {
        $this->addFlash('error', $error);

        return $this->redirectToRoute('app_rider_delivery');
    }

    private function current(User $user): Order
    {
        return $this->orders->findCurrentForRider($this->rider($user)) ?? throw $this->createNotFoundException('You have no delivery in progress.');
    }

    private function rider(User $user): Rider
    {
        return $user->getRider() ?? throw $this->createAccessDeniedException();
    }
}
