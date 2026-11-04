<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\CustomerAddress;
use App\Entity\Payment;
use App\Entity\User;
use App\Form\CheckoutFormType;
use App\Repository\CartItemRepository;
use App\Repository\CustomerAddressRepository;
use App\Service\OrderTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Checkout for the saved cart. Totals come from OrderTransitionService::previewCheckout(),
 * the same pricing placeOrder() uses, so the preview always matches the charged amount.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'app_checkout', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        CartItemRepository $cart,
        CustomerAddressRepository $addresses,
        OrderTransitionService $orders,
    ): Response {
        $customer = $user->getCustomer() ?? throw $this->createAccessDeniedException();
        $lines = $cart->findForCustomer($customer);
        if ([] === $lines) {
            $this->addFlash('error', 'Your cart is empty.');

            return $this->redirectToRoute('app_cart');
        }

        $addressList = $addresses->findForCustomer($customer);
        if ([] === $addressList) {
            return $this->render('checkout/index.html.twig', ['form' => null, 'quote' => null, 'error' => null]);
        }
        $default = array_values(array_filter($addressList, static fn (CustomerAddress $a): bool => $a->isDefault()))[0] ?? $addressList[0];

        $form = $this->createForm(CheckoutFormType::class, [
            'address' => $default,
            'paymentMethod' => Payment::METHOD_COD,
            'tipAmount' => '0',
            'checkoutToken' => Uuid::v4()->toRfc4122(),
        ], ['addresses' => $addressList]);
        $form->handleRequest($request);

        $error = null;
        $input = $this->checkoutInput($lines, $form->getData());
        $placeButton = $form->get('place');
        if ($form->isSubmitted() && $form->isValid() && $placeButton instanceof SubmitButton && $placeButton->isClicked()) {
            try {
                $result = $orders->placeOrder($customer, $input);
                $cart->removeAllForCustomer($customer);
                $this->addFlash('success', sprintf('Order %s was placed. Thank you!', $result['order']->getOrderNumber()));

                return $this->redirectToRoute('app_order_detail', ['id' => $result['order']->getId()]);
            } catch (HttpException|\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        $quote = null;
        try {
            $quote = $orders->previewCheckout($customer, $input);
        } catch (HttpException|\DomainException $exception) {
            $error ??= $exception->getMessage();
        }

        return $this->render('checkout/index.html.twig', [
            'form' => $form,
            'quote' => $quote,
            'error' => $error,
        ], new Response(status: null !== $error && $form->isSubmitted() ? 422 : 200));
    }

    /**
     * The checkout input OrderTransitionService expects, built from the cart and the form.
     *
     * @param list<CartItem>       $lines
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function checkoutInput(array $lines, array $data): array
    {
        $address = $data['address'] ?? null;

        return [
            'restaurant_id' => $lines[0]->getMenuItem()->getMenuCategory()->getRestaurant()->getId(),
            'customer_address_id' => $address instanceof CustomerAddress ? $address->getId() : null,
            'payment_method' => $data['paymentMethod'] ?? null,
            'tip_amount' => trim((string) ($data['tipAmount'] ?? '0')) ?: '0',
            'voucher_code' => trim((string) ($data['voucherCode'] ?? '')) ?: null,
            'customer_notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'checkout_token' => $data['checkoutToken'] ?? null,
            'items' => array_map(static fn (CartItem $line): array => [
                'menu_item_id' => $line->getMenuItem()->getId(),
                'variant_id' => $line->getVariant()?->getId(),
                'addon_ids' => $line->getAddonIds(),
                'quantity' => $line->getQuantity(),
                'special_instructions' => $line->getSpecialInstructions(),
            ], $lines),
        ];
    }
}
