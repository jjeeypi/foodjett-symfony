<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentActor;
use App\Exception\InvalidOrderTransitionException;
use App\Security\Voter\OrderVoter;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer/orders', name: 'api_customer_orders_')]
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerOrderController extends AbstractController
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
    ) {
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Order $order, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order, 'This order can no longer be cancelled by the customer.');

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
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
                    $locked
                        ->setCancellationReason($reason)
                        ->setCancelledBy(OrderActor::CUSTOMER);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund($locked->getPayment(), PaymentActor::CUSTOMER, $reason, $now);
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
