<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\ApiPaginator;
use App\Service\UploadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/menu-items', name: 'api_restaurant_menu_items_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantMenuItemController extends AbstractRestaurantController
{
    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly UploadStorage $uploads,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(MenuItem::class)->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->orderBy('category.sortOrder', 'ASC')->addOrderBy('item.name', 'ASC');
        if ('' !== ($search = trim((string) $request->query->get('search', '')))) {
            $query->andWhere('LOWER(item.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        if ('' !== ($categoryId = trim((string) $request->query->get('category_id', '')))) {
            $query->andWhere('category.id = :categoryId')->setParameter('categoryId', $categoryId);
        }
        if (null !== $request->query->get('is_available')) {
            $available = filter_var($request->query->get('is_available'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (null !== $available) {
                $query->andWhere('item.isAvailable = :available')->setParameter('available', $available);
            }
        }

        return $this->json($this->paginator->paginate($query, $request, $this->serialize(...)));
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $validated = $this->validate($data);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        [$category, $from, $until] = $validated;
        $now = new \DateTimeImmutable();
        $item = (new MenuItem())->setMenuCategory($category)->setCreatedAt($now);
        $this->apply($item, $data, $from, $until);
        $item->setUpdatedAt($now);

        $uploadError = $this->storePhoto($request, $item);
        if ($uploadError instanceof JsonResponse) {
            return $uploadError;
        }
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $this->json(['message' => 'Menu item created.', 'item' => $this->serialize($item)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $item = $this->ownedItem($id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $validated = $this->validate($data, true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        [$category, $from, $until] = $validated;
        if ($category instanceof MenuCategory) {
            $item->setMenuCategory($category);
        }
        $this->apply($item, $data, $from, $until);
        $uploadError = $this->storePhoto($request, $item);
        if ($uploadError instanceof JsonResponse) {
            return $uploadError;
        }
        $item->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Menu item updated.', 'item' => $this->serialize($item)]);
    }

    #[Route('/{id}/toggle-availability', name: 'toggle_availability', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function toggleAvailability(string $id, Request $request): JsonResponse
    {
        $item = $this->ownedItem($id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $available = array_key_exists('is_available', $data) ? filter_var($data['is_available'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : !$item->isAvailable();
        if (null === $available) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => ['is_available' => ['Availability must be true or false.']]], 422);
        }
        $item->setIsAvailable($available)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => $available ? 'Menu item is available.' : 'Menu item is unavailable.', 'is_available' => $available]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(string $id): JsonResponse
    {
        $item = $this->ownedItem($id);
        if (!$item->getOrderItems()->isEmpty()) {
            $item->setIsAvailable(false)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->json(['message' => 'This item has order history, so it was marked unavailable instead of being deleted.', 'is_available' => false], 409);
        }
        $path = $item->getPhotoPath();
        foreach ($item->getVariants()->toArray() as $variant) {
            $this->entityManager->remove($variant);
        }
        foreach ($item->getAddons()->toArray() as $addon) {
            $this->entityManager->remove($addon);
        }
        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->uploads->delete($path);

        return $this->json(['message' => 'Menu item deleted.']);
    }

    private function ownedItem(string $id): MenuItem
    {
        $item = $this->entityManager->getRepository(MenuItem::class)->createQueryBuilder('item')
            ->innerJoin('item.menuCategory', 'category')->addSelect('category')
            ->andWhere('item.id = :id')->setParameter('id', $id)
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
        if (!$item instanceof MenuItem) {
            throw $this->createNotFoundException('Menu item not found.');
        }

        return $item;
    }

    private function ownedCategory(string $id): ?MenuCategory
    {
        return $this->entityManager->getRepository(MenuCategory::class)->createQueryBuilder('category')
            ->andWhere('category.id = :id')->setParameter('id', $id)
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
    }

    /** @param array<string, mixed> $data
     * @return array{MenuCategory|null, \DateTimeImmutable|null, \DateTimeImmutable|null}|JsonResponse
     */
    private function validate(array $data, bool $partial = false): array|JsonResponse
    {
        $errors = [];
        if ((!$partial || array_key_exists('name', $data)) && '' === trim((string) ($data['name'] ?? ''))) {
            $errors['name'][] = 'An item name is required.';
        }
        if ((!$partial || array_key_exists('base_price', $data)) && (!is_numeric($data['base_price'] ?? null) || (float) $data['base_price'] < 0)) {
            $errors['base_price'][] = 'Base price must be zero or greater.';
        }
        $category = null;
        if (!$partial || array_key_exists('menu_category_id', $data)) {
            $category = $this->ownedCategory((string) ($data['menu_category_id'] ?? ''));
            if (!$category instanceof MenuCategory) {
                $errors['menu_category_id'][] = 'The selected category does not belong to this restaurant.';
            }
        }
        $from = $this->parseTime($data['available_from'] ?? null);
        $until = $this->parseTime($data['available_until'] ?? null);
        if (array_key_exists('available_from', $data) && null !== $data['available_from'] && '' !== $data['available_from'] && null === $from) {
            $errors['available_from'][] = 'Time must use HH:MM format.';
        }
        if (array_key_exists('available_until', $data) && null !== $data['available_until'] && '' !== $data['available_until'] && null === $until) {
            $errors['available_until'][] = 'Time must use HH:MM format.';
        }
        foreach (['is_available', 'is_featured'] as $boolean) {
            if (array_key_exists($boolean, $data) && null === filter_var($data[$boolean], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)) {
                $errors[$boolean][] = 'This value must be true or false.';
            }
        }
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }

        return [$category, $from, $until];
    }

    /** @param array<string, mixed> $data */
    private function apply(MenuItem $item, array $data, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until): void
    {
        if (array_key_exists('name', $data)) {
            $item->setName(trim((string) $data['name']));
        }
        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $item->setDescription('' === $description ? null : $description);
        }
        if (array_key_exists('base_price', $data)) {
            $item->setBasePrice(number_format((float) $data['base_price'], 2, '.', ''));
        }
        if (array_key_exists('is_available', $data)) {
            $item->setIsAvailable((bool) filter_var($data['is_available'], FILTER_VALIDATE_BOOL));
        }
        if (array_key_exists('is_featured', $data)) {
            $item->setIsFeatured((bool) filter_var($data['is_featured'], FILTER_VALIDATE_BOOL));
        }
        if (array_key_exists('available_from', $data)) {
            $item->setAvailableFrom($from);
        }
        if (array_key_exists('available_until', $data)) {
            $item->setAvailableUntil($until);
        }
    }

    private function storePhoto(Request $request, MenuItem $item): ?JsonResponse
    {
        $file = $request->files->get('photo');
        if (null === $file) {
            return null;
        }
        try {
            $path = $this->uploads->store($file, 'restaurants/menu-items', self::IMAGE_MIME_TYPES);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage(), 'errors' => ['photo' => [$exception->getMessage()]]], 422);
        }
        $this->uploads->delete($item->getPhotoPath());
        $item->setPhotoPath($path);

        return null;
    }

    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!H:i', $value) ?: null;
    }

    /** @return array<string, mixed> */
    private function serialize(MenuItem $item): array
    {
        return [
            'id' => $item->getId(), 'name' => $item->getName(), 'description' => $item->getDescription(),
            'photo_path' => $item->getPhotoPath(), 'base_price' => $item->getBasePrice(), 'is_available' => $item->isAvailable(),
            'available_from' => $item->getAvailableFrom()?->format('H:i'), 'available_until' => $item->getAvailableUntil()?->format('H:i'),
            'is_featured' => $item->isFeatured(),
            'category' => ['id' => $item->getMenuCategory()->getId(), 'name' => $item->getMenuCategory()->getName()],
            'variants' => array_map(static fn (MenuItemVariant $variant): array => ['id' => $variant->getId(), 'name' => $variant->getName(), 'price_delta' => $variant->getPriceDelta()], $item->getVariants()->toArray()),
            'addons' => array_map(static fn (MenuItemAddon $addon): array => ['id' => $addon->getId(), 'name' => $addon->getName(), 'price' => $addon->getPrice(), 'is_available' => $addon->isAvailable()], $item->getAddons()->toArray()),
        ];
    }
}
