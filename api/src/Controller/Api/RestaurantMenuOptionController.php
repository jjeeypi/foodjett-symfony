<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/menu-items/{itemId}', name: 'api_restaurant_menu_options_', requirements: ['itemId' => '\\d+'])]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantMenuOptionController extends AbstractRestaurantController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/variants', name: 'variant_store', methods: ['POST'])]
    public function storeVariant(string $itemId, Request $request): JsonResponse
    {
        $item = $this->ownedItem($itemId);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validateNameAndMoney($data, 'price_delta', true);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $now = new \DateTimeImmutable();
        $variant = (new MenuItemVariant())->setMenuItem($item)->setName(trim((string) $data['name']))
            ->setPriceDelta(number_format((float) $data['price_delta'], 2, '.', ''))->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($variant);
        $this->entityManager->flush();

        return $this->json(['message' => 'Variant created.', 'variant' => $this->serializeVariant($variant)], 201);
    }

    #[Route('/variants/{id}', name: 'variant_update', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function updateVariant(string $itemId, string $id, Request $request): JsonResponse
    {
        $item = $this->ownedItem($itemId);
        $variant = $this->ownedVariant($item, $id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validateNameAndMoney($data, 'price_delta', true, true);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        if (array_key_exists('name', $data)) {
            $variant->setName(trim((string) $data['name']));
        }
        if (array_key_exists('price_delta', $data)) {
            $variant->setPriceDelta(number_format((float) $data['price_delta'], 2, '.', ''));
        }
        $variant->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Variant updated.', 'variant' => $this->serializeVariant($variant)]);
    }

    #[Route('/variants/{id}', name: 'variant_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function deleteVariant(string $itemId, string $id): JsonResponse
    {
        $variant = $this->ownedVariant($this->ownedItem($itemId), $id);
        if (!$variant->getOrderItems()->isEmpty()) {
            return $this->json(['message' => 'This variant is part of order history and cannot be deleted.'], 409);
        }
        $this->entityManager->remove($variant);
        $this->entityManager->flush();

        return $this->json(['message' => 'Variant deleted.']);
    }

    #[Route('/addons', name: 'addon_store', methods: ['POST'])]
    public function storeAddon(string $itemId, Request $request): JsonResponse
    {
        $item = $this->ownedItem($itemId);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validateNameAndMoney($data, 'price', false);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $now = new \DateTimeImmutable();
        $addon = (new MenuItemAddon())->setMenuItem($item)->setName(trim((string) $data['name']))
            ->setPrice(number_format((float) $data['price'], 2, '.', ''))->setIsAvailable($this->bool($data['is_available'] ?? true))
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($addon);
        $this->entityManager->flush();

        return $this->json(['message' => 'Add-on created.', 'addon' => $this->serializeAddon($addon)], 201);
    }

    #[Route('/addons/{id}', name: 'addon_update', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function updateAddon(string $itemId, string $id, Request $request): JsonResponse
    {
        $item = $this->ownedItem($itemId);
        $addon = $this->ownedAddon($item, $id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validateNameAndMoney($data, 'price', false, true);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        if (array_key_exists('name', $data)) {
            $addon->setName(trim((string) $data['name']));
        }
        if (array_key_exists('price', $data)) {
            $addon->setPrice(number_format((float) $data['price'], 2, '.', ''));
        }
        if (array_key_exists('is_available', $data)) {
            $addon->setIsAvailable($this->bool($data['is_available']));
        }
        $addon->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Add-on updated.', 'addon' => $this->serializeAddon($addon)]);
    }

    #[Route('/addons/{id}', name: 'addon_delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function deleteAddon(string $itemId, string $id): JsonResponse
    {
        $addon = $this->ownedAddon($this->ownedItem($itemId), $id);
        if (!$addon->getOrderItemAddons()->isEmpty()) {
            return $this->json(['message' => 'This add-on is part of order history and cannot be deleted. Mark it unavailable instead.'], 409);
        }
        $this->entityManager->remove($addon);
        $this->entityManager->flush();

        return $this->json(['message' => 'Add-on deleted.']);
    }

    private function ownedItem(string $id): MenuItem
    {
        $item = $this->entityManager->getRepository(MenuItem::class)->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')
            ->andWhere('item.id = :id')->setParameter('id', $id)
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
        if (!$item instanceof MenuItem) {
            throw $this->createNotFoundException('Menu item not found.');
        }

        return $item;
    }

    private function ownedVariant(MenuItem $item, string $id): MenuItemVariant
    {
        $variant = $this->entityManager->getRepository(MenuItemVariant::class)->findOneBy(['id' => $id, 'menuItem' => $item]);
        if (!$variant instanceof MenuItemVariant) {
            throw $this->createNotFoundException('Variant not found.');
        }

        return $variant;
    }

    private function ownedAddon(MenuItem $item, string $id): MenuItemAddon
    {
        $addon = $this->entityManager->getRepository(MenuItemAddon::class)->findOneBy(['id' => $id, 'menuItem' => $item]);
        if (!$addon instanceof MenuItemAddon) {
            throw $this->createNotFoundException('Add-on not found.');
        }

        return $addon;
    }

    /** @param array<string, mixed> $data */
    private function validateNameAndMoney(array $data, string $moneyField, bool $allowNegative, bool $partial = false): ?JsonResponse
    {
        $errors = [];
        if ((!$partial || array_key_exists('name', $data)) && '' === trim((string) ($data['name'] ?? ''))) {
            $errors['name'][] = 'A name is required.';
        }
        if ((!$partial || array_key_exists($moneyField, $data)) && !is_numeric($data[$moneyField] ?? null)) {
            $errors[$moneyField][] = 'This value must be numeric.';
        } elseif (array_key_exists($moneyField, $data) && !$allowNegative && (float) $data[$moneyField] < 0) {
            $errors[$moneyField][] = 'This value cannot be negative.';
        }
        if (array_key_exists('is_available', $data) && null === filter_var($data['is_available'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)) {
            $errors['is_available'][] = 'Availability must be true or false.';
        }

        return [] === $errors ? null : $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
    }

    private function bool(mixed $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array<string, mixed> */
    private function serializeVariant(MenuItemVariant $variant): array
    {
        return ['id' => $variant->getId(), 'name' => $variant->getName(), 'price_delta' => $variant->getPriceDelta()];
    }

    /** @return array<string, mixed> */
    private function serializeAddon(MenuItemAddon $addon): array
    {
        return ['id' => $addon->getId(), 'name' => $addon->getName(), 'price' => $addon->getPrice(), 'is_available' => $addon->isAvailable()];
    }
}
