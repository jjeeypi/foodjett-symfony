<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\CheckoutValidationException;
use App\Service\CheckoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer/checkout/preview', name: 'api_customer_checkout_preview', methods: ['POST'])]
#[IsGranted('ROLE_CUSTOMER')]
final class CheckoutPreviewController extends AbstractController
{
    public function __construct(private readonly CheckoutService $checkout)
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
            $quote = $this->checkout->preview($user->getCustomer(), $input);
        } catch (CheckoutValidationException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return $this->json(['quote' => $quote]);
    }
}
