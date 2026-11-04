<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\Customer;
use App\Entity\MenuItem;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\MenuItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer's saved cart. It holds items from one restaurant at a time; checkout
 * recomputes every price from the menu.
 */
#[IsGranted('ROLE_CUSTOMER')]
#[Route('/cart')]
final class CartController extends AbstractController
{
    private const MAX_LINES = 100;
    private const MAX_QUANTITY = 99;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CartItemRepository $cart,
    ) {
    }

    #[Route('', name: 'app_cart', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $lines = $this->cart->findForCustomer($this->customer($user));
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, $line->getLineTotal(), 2);
        }

        return $this->render('cart/index.html.twig', [
            'lines' => $lines,
            'restaurant' => [] === $lines ? null : $this->restaurantOf($lines[0]->getMenuItem()),
            'subtotal' => $subtotal,
        ]);
    }

    #[Route('/add/{id}', name: 'app_cart_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function add(string $id, Request $request, #[CurrentUser] User $user, MenuItemRepository $menuItems): Response
    {
        $this->assertToken($request);
        $customer = $this->customer($user);
        $item = $menuItems->find($id) ?? throw $this->createNotFoundException('Menu item not found.');
        $restaurant = $this->restaurantOf($item);
        $back = $this->redirectToRoute('app_restaurant_menu', ['slug' => $restaurant->getSlug()]);

        if (Restaurant::APPROVAL_APPROVED !== $restaurant->getApprovalStatus()) {
            throw $this->createNotFoundException('Menu item not found.');
        }
        if (!$item->isOrderableNow()) {
            $this->addFlash('error', sprintf('%s is not available right now.', $item->getName()));

            return $back;
        }

        $quantity = $request->getPayload()->getInt('quantity', 1);
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            $this->addFlash('error', 'Choose a quantity from 1 to 99.');

            return $back;
        }

        $variant = null;
        $variantId = $request->getPayload()->getString('variant');
        if ('' !== $variantId) {
            $variant = $item->getVariants()->findFirst(static fn ($key, $candidate): bool => (string) $candidate->getId() === $variantId);
            if (null === $variant) {
                $this->addFlash('error', 'Choose one of the listed options.');

                return $back;
            }
        }

        $offered = [];
        foreach ($item->getAddons() as $addon) {
            if ($addon->isAvailable()) {
                $offered[] = (string) $addon->getId();
            }
        }
        $addonIds = array_values(array_unique(array_map('strval', $request->getPayload()->all('addons'))));
        if ([] !== array_diff($addonIds, $offered)) {
            $this->addFlash('error', 'One of the chosen add-ons is no longer available.');

            return $back;
        }
        sort($addonIds);

        $instructions = trim($request->getPayload()->getString('instructions'));
        $instructions = '' === $instructions ? null : mb_substr($instructions, 0, 1000);

        $lines = $this->cart->findForCustomer($customer);
        if ([] !== $lines) {
            $current = $this->restaurantOf($lines[0]->getMenuItem());
            if ($current !== $restaurant) {
                $this->addFlash('error', sprintf(
                    'Your cart has items from %s. Empty it to order from %s.',
                    $current->getName(),
                    $restaurant->getName(),
                ));

                return $back;
            }
        }

        $now = new \DateTimeImmutable();
        foreach ($lines as $line) {
            if ($line->isSameChoiceAs($item, $variant, $addonIds, $instructions)) {
                $line->setQuantity(min(self::MAX_QUANTITY, $line->getQuantity() + $quantity))->setUpdatedAt($now);
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('Added %s to your cart.', $item->getName()));

                return $back;
            }
        }
        if (count($lines) >= self::MAX_LINES) {
            $this->addFlash('error', 'Your cart is full.');

            return $back;
        }

        $line = (new CartItem())
            ->setMenuItem($item)
            ->setVariant($variant)
            ->setAddonIds($addonIds)
            ->setQuantity($quantity)
            ->setSpecialInstructions($instructions)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $customer->addCartItem($line);
        $this->entityManager->persist($line);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Added %s to your cart.', $item->getName()));

        return $back;
    }

    #[Route('/{id}/quantity', name: 'app_cart_quantity', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function quantity(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertToken($request);
        $line = $this->cart->findOneForCustomer($id, $this->customer($user)) ?? throw $this->createNotFoundException();
        $quantity = $request->getPayload()->getInt('quantity');
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            $this->addFlash('error', 'Choose a quantity from 1 to 99.');
        } else {
            $line->setQuantity($quantity)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_cart');
    }

    #[Route('/{id}/remove', name: 'app_cart_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertToken($request);
        $customer = $this->customer($user);
        $line = $this->cart->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException();
        $customer->removeCartItem($line);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_cart');
    }

    #[Route('/clear', name: 'app_cart_clear', methods: ['POST'])]
    public function clear(Request $request, #[CurrentUser] User $user): Response
    {
        $this->assertToken($request);
        $this->cart->removeAllForCustomer($this->customer($user));
        $this->addFlash('success', 'Your cart is empty.');

        return $this->redirectToRoute('app_cart');
    }

    private function customer(User $user): Customer
    {
        return $user->getCustomer() ?? throw $this->createAccessDeniedException();
    }

    private function restaurantOf(MenuItem $item): Restaurant
    {
        return $item->getMenuCategory()->getRestaurant();
    }

    private function assertToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('cart', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token. Reload the page and try again.');
        }
    }
}
