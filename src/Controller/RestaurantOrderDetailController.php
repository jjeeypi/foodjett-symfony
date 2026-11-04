<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Form\OrderRejectFormType;
use App\Form\PrepTimeFormType;
use App\Repository\OrderRepository;
use App\Security\Voter\ApprovedAccountVoter;
use App\Security\Voter\OrderVoter;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One order in the restaurant's queue: accept with a prep estimate (the rider search starts
 * right away), reject with a reason, add prep time, and mark the food ready.
 */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
#[Route('/restaurant/orders/{id}', requirements: ['id' => '\d+'])]
final class RestaurantOrderDetailController extends AbstractController
{
    /** Statuses in which the food can be marked ready: accepted, rider search or pickup under way. */
    private const READY_FROM = [Order::STATUS_FINDING_RIDER, Order::STATUS_RIDER_ASSIGNED, Order::STATUS_AT_RESTAURANT];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderTransitionService $transitions,
    ) {
    }

    #[Route('', name: 'app_restaurant_order_detail', methods: ['GET'])]
    public function index(string $id, #[CurrentUser] User $user): Response
    {
        return $this->renderDetail($this->owned($id, $user));
    }

    #[Route('/accept', name: 'app_restaurant_order_accept', methods: ['POST'])]
    public function accept(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->owned($id, $user);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        $form = $this->acceptForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, accept: $form);
        }

        $minutes = (int) $form->get('minutes')->getData();
        try {
            $this->transitions->transitionSequence(
                $order,
                [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_FINDING_RIDER],
                Order::ACTOR_RESTAURANT,
                ['Order accepted', 'Food preparation started', 'Rider search started in parallel with preparation'],
                static function (Order $locked, \DateTimeImmutable $now) use ($minutes): void {
                    $locked->setAcceptedAt($now)->setEstimatedPrepMinutes($minutes)
                        ->setEstimatedReadyAt($now->modify(sprintf('+%d minutes', $minutes)));
                },
            );
            $this->addFlash('success', 'Order accepted. We are looking for a rider.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_restaurant_order_detail', ['id' => $id]);
    }

    #[Route('/reject', name: 'app_restaurant_order_reject', methods: ['POST'])]
    public function reject(string $id, Request $request, #[CurrentUser] User $user, PaymentTransitionService $payments): Response
    {
        $order = $this->owned($id, $user);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        $form = $this->createForm(OrderRejectFormType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, reject: $form);
        }

        $reason = trim((string) $form->get('reason')->getData());
        try {
            $this->transitions->transition(
                $order,
                Order::STATUS_REJECTED_BY_RESTAURANT,
                Order::ACTOR_RESTAURANT,
                $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason, $payments): void {
                    $locked->setRejectionReason($reason);
                    if (null !== $locked->getPayment()) {
                        $payments->cancelOrRefund($locked->getPayment(), Payment::ACTOR_SYSTEM, 'Restaurant rejected order: '.$reason, $now);
                    }
                },
            );
            $this->addFlash('success', 'Order rejected.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_restaurant_order_detail', ['id' => $id]);
    }

    #[Route('/extend', name: 'app_restaurant_order_extend', methods: ['POST'])]
    public function extend(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->owned($id, $user);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        if (!$this->canExtend($order)) {
            $this->addFlash('error', 'Preparation time cannot be extended for this order.');

            return $this->redirectToRoute('app_restaurant_order_detail', ['id' => $id]);
        }
        $form = $this->extendForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, extend: $form);
        }

        $minutes = (int) $form->get('minutes')->getData();
        $this->transitions->recordUpdate(
            $order,
            Order::ACTOR_RESTAURANT,
            'prep_time_extended',
            sprintf('Preparation time extended by %d minutes.', $minutes),
            static function (Order $locked) use ($minutes): void {
                $locked->setPrepExtendedMinutes(($locked->getPrepExtendedMinutes() ?? 0) + $minutes)
                    ->setEstimatedReadyAt($locked->getEstimatedReadyAt()?->modify(sprintf('+%d minutes', $minutes)));
            },
        );
        $this->addFlash('success', sprintf('Added %d minutes.', $minutes));

        return $this->redirectToRoute('app_restaurant_order_detail', ['id' => $id]);
    }

    #[Route('/ready', name: 'app_restaurant_order_ready', methods: ['POST'])]
    public function ready(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->owned($id, $user);
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        if (!$this->isCsrfTokenValid('ready'.$order->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$this->canMarkReady($order)) {
            $this->addFlash('error', 'Food can only be marked ready once, after you accept the order.');

            return $this->redirectToRoute('app_restaurant_order_detail', ['id' => $id]);
        }

        $this->transitions->recordUpdate(
            $order,
            Order::ACTOR_RESTAURANT,
            'food_ready',
            'Restaurant marked the food ready for pickup.',
            static function (Order $locked, \DateTimeImmutable $now): void {
                $locked->setReadyAt($now);
            },
            Order::STATUS_READY,
        );
        $this->addFlash('success', 'Marked ready for pickup.');

        return $this->redirectToRoute('app_restaurant_order_detail', ['id' => $id]);
    }

    private function renderDetail(Order $order, ?FormInterface $accept = null, ?FormInterface $reject = null, ?FormInterface $extend = null): Response
    {
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);
        $canAct = Order::STATUS_PLACED === $order->getStatus() && $this->isGranted(OrderVoter::UPDATE_AS_RESTAURANT, $order);
        $history = $order->getStatusHistory()->toArray();
        usort($history, static fn ($a, $b): int => ($a->getCreatedAt() <=> $b->getCreatedAt()) ?: ((int) $a->getId() <=> (int) $b->getId()));

        return $this->render('restaurant_order_detail/index.html.twig', [
            'order' => $order,
            'history' => $history,
            'accept_form' => $canAct ? ($accept ?? $this->acceptForm()) : null,
            'reject_form' => $canAct ? ($reject ?? $this->createForm(OrderRejectFormType::class)) : null,
            'extend_form' => $this->canExtend($order) ? ($extend ?? $this->extendForm()) : null,
            'can_mark_ready' => $this->canMarkReady($order),
        ], new Response(status: null !== ($accept ?? $reject ?? $extend) ? 422 : 200));
    }

    private function acceptForm(): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('accept', PrepTimeFormType::class, null, [
            'minutes_label' => 'Ready in (minutes)',
            'max_minutes' => 180,
        ]);
    }

    private function extendForm(): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('extend', PrepTimeFormType::class, null, [
            'minutes_label' => 'Extra minutes',
            'max_minutes' => 120,
        ]);
    }

    private function canExtend(Order $order): bool
    {
        return null !== $order->getEstimatedReadyAt() && null === $order->getReadyAt() && !Order::isTerminalStatus($order->getStatus());
    }

    private function canMarkReady(Order $order): bool
    {
        return null === $order->getReadyAt() && in_array($order->getStatus(), self::READY_FROM, true);
    }

    private function owned(string $id, User $user): Order
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();

        return $this->orders->findOneForRestaurant($id, $restaurant) ?? throw $this->createNotFoundException('Order not found.');
    }
}
