<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Exception\InvalidOrderTransitionException;
use App\Security\Voter\ApprovedAccountVoter;
use App\Security\Voter\OrderVoter;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/orders', name: 'api_restaurant_orders_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->authenticatedUser()->getRestaurant();
        if (null === $restaurant) {
            return $this->json(['message' => 'Restaurant profile not found.'], 404);
        }

        $builder = $this->entityManager->getRepository(Order::class)->createQueryBuilder('o')
            ->andWhere('o.restaurant = :restaurant')
            ->setParameter('restaurant', $restaurant)
            ->orderBy('o.placedAt', 'DESC')
            ->setMaxResults(100);

        $statusValue = $request->query->getString('status');
        if ('' !== $statusValue) {
            $status = OrderStatus::tryFrom($statusValue);
            if (null === $status) {
                return $this->json(['message' => 'Unknown order status filter.'], 422);
            }
            $builder->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $this->json([
            'orders' => array_map(fn (Order $order): array => $this->serializeOrder($order), $builder->getQuery()->getResult()),
        ]);
    }

    #[Route('/{id}/accept', name: 'accept', methods: ['POST'])]
    public function accept(Order $order, Request $request): JsonResponse
    {
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
                    $locked
                        ->setAcceptedAt($now)
                        ->setEstimatedPrepMinutes($minutes)
                        ->setEstimatedReadyAt($now->modify(sprintf('+%d minutes', $minutes)));
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json(['order' => $this->serializeOrder($order)]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(Order $order, Request $request): JsonResponse
    {
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

        return $this->json(['order' => $this->serializeOrder($order)]);
    }

    #[Route('/{id}/extend-prep-time', name: 'extend_prep_time', methods: ['POST'])]
    public function extendPrepTime(Order $order, Request $request): JsonResponse
    {
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
                $locked
                    ->setPrepExtendedMinutes(($locked->getPrepExtendedMinutes() ?? 0) + $minutes)
                    ->setEstimatedReadyAt($locked->getEstimatedReadyAt()?->modify(sprintf('+%d minutes', $minutes)));
            },
        );

        return $this->json(['order' => $this->serializeOrder($order)]);
    }

    #[Route('/{id}/mark-ready', name: 'mark_ready', methods: ['POST'])]
    public function markReady(Order $order): JsonResponse
    {
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

        return $this->json(['order' => $this->serializeOrder($order)]);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function body(Request $request): array|JsonResponse
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
    }

    /** @return array<string, mixed> */
    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'status' => $order->getStatus()->value,
            'total_amount' => $order->getTotalAmount(),
            'payment_method' => $order->getPaymentMethod()->value,
            'estimated_prep_minutes' => $order->getEstimatedPrepMinutes(),
            'estimated_ready_at' => $order->getEstimatedReadyAt()?->format(\DateTimeInterface::ATOM),
            'ready_at' => $order->getReadyAt()?->format(\DateTimeInterface::ATOM),
            'rider_search_started_at' => $order->getRiderSearchStartedAt()?->format(\DateTimeInterface::ATOM),
            'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
