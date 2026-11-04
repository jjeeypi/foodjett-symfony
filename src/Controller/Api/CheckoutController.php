<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\User;
use App\Service\OrderTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer/checkout', name: 'api_customer_checkout', methods: ['POST'])]
#[IsGranted('ROLE_CUSTOMER')]
final class CheckoutController extends AbstractController
{
    public function __construct(private readonly OrderTransitionService $transitions)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || null === $user->getCustomer()) {
            return $this->json(['message' => 'Customer profile not found.'], 404);
        }

        try {
            $input = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
        }

        try {
            $result = $this->transitions->placeOrder($user->getCustomer(), $input);
        } catch (HttpException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return $this->json([
            'created' => $result['created'],
            'order' => $this->serializeOrder($result['order']),
        ], $result['created'] ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'checkout_token' => $order->getCheckoutToken(),
            'status' => $order->getStatus(),
            'subtotal' => $order->getSubtotal(),
            'delivery_fee' => $order->getDeliveryFee(),
            'service_fee' => $order->getServiceFee(),
            'discount_amount' => $order->getDiscountAmount(),
            'tip_amount' => $order->getTipAmount(),
            'total_amount' => $order->getTotalAmount(),
            'payment_method' => $order->getPaymentMethod(),
            'payment_status' => $order->getPayment()?->getStatus(),
            'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
