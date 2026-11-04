<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\Payment;
use App\Entity\RestaurantReview;
use App\Entity\RiderReview;
use App\Entity\User;
use App\Form\OrderCancelFormType;
use App\Form\OrderReportFormType;
use App\Form\OrderReviewFormType;
use App\Repository\CartItemRepository;
use App\Repository\OrderRepository;
use App\Security\Voter\OrderVoter;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One order for its customer: status timeline, items, payment and rider, plus cancel,
 * reorder, review and report-a-problem actions.
 */
#[IsGranted('ROLE_CUSTOMER')]
#[Route('/orders/{id}', requirements: ['id' => '\d+'])]
final class OrderDetailController extends AbstractController
{
    /** Statuses in which the customer may see where the rider is. */
    private const RIDER_VISIBLE = [
        Order::STATUS_RIDER_ASSIGNED, Order::STATUS_AT_RESTAURANT, Order::STATUS_PICKED_UP,
        Order::STATUS_ON_THE_WAY, Order::STATUS_ARRIVED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRepository $orders,
    ) {
    }

    #[Route('', name: 'app_order_detail', methods: ['GET'])]
    public function index(string $id, #[CurrentUser] User $user): Response
    {
        return $this->renderDetail($this->ownedOrder($id, $user));
    }

    #[Route('/cancel', name: 'app_order_cancel', methods: ['POST'])]
    public function cancel(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
        OrderTransitionService $transitions,
        PaymentTransitionService $payments,
    ): Response {
        $order = $this->ownedOrder($id, $user);
        $form = $this->createForm(OrderCancelFormType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, cancelForm: $form);
        }
        if (!$this->isGranted(OrderVoter::CANCEL, $order)) {
            $this->addFlash('error', 'This order can no longer be cancelled.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }

        $reason = trim((string) $form->get('reason')->getData()) ?: 'Cancelled by customer.';
        try {
            $transitions->transition(
                $order,
                Order::STATUS_CANCELLED_BY_CUSTOMER,
                Order::ACTOR_CUSTOMER,
                $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason, $payments): void {
                    $locked->setCancellationReason($reason)->setCancelledBy(Order::ACTOR_CUSTOMER);
                    if (null !== $locked->getPayment()) {
                        $payments->cancelOrRefund($locked->getPayment(), Payment::ACTOR_CUSTOMER, $reason, $now);
                    }
                },
            );
            $this->addFlash('success', 'Your order was cancelled.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_order_detail', ['id' => $id]);
    }

    /** Puts this order's items back in the cart, replacing what was there. */
    #[Route('/reorder', name: 'app_order_reorder', methods: ['POST'])]
    public function reorder(string $id, Request $request, #[CurrentUser] User $user, CartItemRepository $cart): Response
    {
        $order = $this->ownedOrder($id, $user);
        if (!$this->isCsrfTokenValid('reorder'.$order->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!Order::isTerminalStatus($order->getStatus())) {
            $this->addFlash('error', 'Only a past order can be reordered.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }

        $unavailable = [];
        foreach ($order->getItems() as $item) {
            $menuItem = $item->getMenuItem();
            if (!$menuItem->isOrderableNow()) {
                $unavailable[] = $menuItem->getName();
                continue;
            }
            foreach ($item->getAddons() as $addon) {
                if (!$addon->getMenuItemAddon()->isAvailable()) {
                    $unavailable[] = $menuItem->getName().' / '.$addon->getMenuItemAddon()->getName();
                }
            }
        }
        if ([] !== $unavailable) {
            $this->addFlash('error', 'Some items are no longer available: '.implode(', ', $unavailable).'.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }

        $customer = $this->customer($user);
        $cart->removeAllForCustomer($customer);
        $now = new \DateTimeImmutable();
        foreach ($order->getItems() as $item) {
            $addonIds = array_map(static fn ($addon): string => (string) $addon->getMenuItemAddon()->getId(), $item->getAddons()->toArray());
            sort($addonIds);
            $line = (new CartItem())
                ->setMenuItem($item->getMenuItem())
                ->setVariant($item->getMenuItemVariant())
                ->setAddonIds($addonIds)
                ->setQuantity($item->getQuantity())
                ->setSpecialInstructions($item->getSpecialInstructions())
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $customer->addCartItem($line);
            $this->entityManager->persist($line);
        }
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Your cart now has the items from order %s.', $order->getOrderNumber()));

        return $this->redirectToRoute('app_cart');
    }

    #[Route('/review', name: 'app_order_review', methods: ['POST'])]
    public function review(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->ownedOrder($id, $user);
        $form = $this->reviewForm($order);
        if (null === $form) {
            $this->addFlash('error', 'This order cannot be reviewed.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, reviewForm: $form);
        }

        $data = $form->getData();
        $comment = trim((string) ($data['comment'] ?? '')) ?: null;
        $now = new \DateTimeImmutable();
        $customer = $this->customer($user);
        if (null !== ($data['restaurantRating'] ?? null)) {
            $review = (new RestaurantReview())->setOrder($order)->setCustomer($customer)->setRestaurant($order->getRestaurant())
                ->setRating($data['restaurantRating'])->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
            $order->setRestaurantReview($review);
            $this->entityManager->persist($review);
        }
        if (null !== ($data['riderRating'] ?? null)) {
            $review = (new RiderReview())->setOrder($order)->setCustomer($customer)->setRider($order->getRider())
                ->setRating($data['riderRating'])->setComment($comment)->setCreatedAt($now)->setUpdatedAt($now);
            $order->setRiderReview($review);
            $this->entityManager->persist($review);
        }
        $this->entityManager->flush();
        $this->addFlash('success', 'Thanks for your review.');

        return $this->redirectToRoute('app_order_detail', ['id' => $id]);
    }

    #[Route('/report', name: 'app_order_report', methods: ['POST'])]
    public function report(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->ownedOrder($id, $user);
        if (!$this->canReport($order)) {
            $this->addFlash('error', 'Problems can be reported while an order is active or within seven days after delivery.');

            return $this->redirectToRoute('app_order_detail', ['id' => $id]);
        }
        $report = new OrderReport();
        $form = $this->createForm(OrderReportFormType::class, $report);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, reportForm: $form);
        }

        $now = new \DateTimeImmutable();
        $report->setOrder($order)->setReportedBy($user)->setDescription(trim($report->getDescription()))
            ->setStatus(OrderReport::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($report);
        $this->entityManager->flush();
        $this->addFlash('success', 'Your report was sent. Our team will look into it.');

        return $this->redirectToRoute('app_order_detail', ['id' => $id]);
    }

    private function renderDetail(
        Order $order,
        ?FormInterface $cancelForm = null,
        ?FormInterface $reviewForm = null,
        ?FormInterface $reportForm = null,
    ): Response {
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);
        $history = $order->getStatusHistory()->toArray();
        usort($history, static fn ($a, $b): int => ($a->getCreatedAt() <=> $b->getCreatedAt()) ?: ((int) $a->getId() <=> (int) $b->getId()));
        $failed = null !== $cancelForm || null !== $reviewForm || null !== $reportForm;

        return $this->render('order_detail/index.html.twig', [
            'order' => $order,
            'history' => $history,
            'rider_location_visible' => null !== $order->getRider() && in_array($order->getStatus(), self::RIDER_VISIBLE, true),
            'cancel_form' => $this->isGranted(OrderVoter::CANCEL, $order)
                ? ($cancelForm ?? $this->createForm(OrderCancelFormType::class)) : null,
            'review_form' => $reviewForm ?? $this->reviewForm($order),
            'report_form' => $this->canReport($order)
                ? ($reportForm ?? $this->createForm(OrderReportFormType::class, new OrderReport())) : null,
        ], new Response(status: $failed ? 422 : 200));
    }

    /** The review form for what is still unrated, or null when nothing can be reviewed. */
    private function reviewForm(Order $order): ?FormInterface
    {
        if (Order::STATUS_DELIVERED !== $order->getStatus() || null === $order->getDeliveredAt()) {
            return null;
        }
        $rateRestaurant = null === $order->getRestaurantReview();
        $rateRider = null !== $order->getRider() && null === $order->getRiderReview();
        if (!$rateRestaurant && !$rateRider) {
            return null;
        }

        return $this->createForm(OrderReviewFormType::class, null, [
            'rate_restaurant' => $rateRestaurant,
            'rate_rider' => $rateRider,
        ]);
    }

    private function canReport(Order $order): bool
    {
        if (!Order::isTerminalStatus($order->getStatus())) {
            return true;
        }

        return Order::STATUS_DELIVERED === $order->getStatus()
            && null !== $order->getDeliveredAt()
            && new \DateTimeImmutable() <= $order->getDeliveredAt()->modify('+7 days');
    }

    private function ownedOrder(string $id, User $user): Order
    {
        return $this->orders->findOneForCustomer($id, $this->customer($user))
            ?? throw $this->createNotFoundException('Order not found.');
    }

    private function customer(User $user): Customer
    {
        return $user->getCustomer() ?? throw $this->createAccessDeniedException();
    }
}
