<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Exception\InvalidOrderTransitionException;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/orders', name: 'api_admin_orders_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderController extends AbstractController
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
    ) {
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Order $order, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }
        $reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');
        if ('' === $reason || mb_strlen($reason) > 2000) {
            return $this->json(['message' => 'A cancellation reason of at most 2000 characters is required.'], 422);
        }
        if ($order->getStatus()->isTerminal()) {
            return $this->json(['message' => 'A terminal order cannot be cancelled.'], 409);
        }

        try {
            $order = $this->transitions->transition(
                $order,
                OrderStatus::CANCELLED_BY_ADMIN,
                OrderActor::ADMIN,
                $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason): void {
                    $locked
                        ->setCancellationReason($reason)
                        ->setCancelledBy(OrderActor::ADMIN);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund($locked->getPayment(), PaymentActor::ADMIN, $reason, $now);
                    }
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            return $this->json(['message' => $exception->getMessage()], 409);
        }

        return $this->json([
            'order' => [
                'id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'status' => $order->getStatus()->value,
                'cancellation_reason' => $order->getCancellationReason(),
                'cancelled_by' => $order->getCancelledBy()?->value,
                'payment_status' => $order->getPayment()?->getStatus()->value,
            ],
        ]);
    }
}
