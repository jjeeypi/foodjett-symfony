<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\MenuCategory;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/menu-categories', name: 'api_restaurant_menu_categories_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantMenuCategoryController extends AbstractRestaurantController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(MenuCategory::class)->createQueryBuilder('category')
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->orderBy('category.sortOrder', 'ASC')->addOrderBy('category.name', 'ASC');

        return $this->json($this->paginator->paginate($query, $request, $this->serialize(...)));
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validate($data);
        if ($error instanceof JsonResponse) {
            return $error;
        }

        $now = new \DateTimeImmutable();
        $category = (new MenuCategory())
            ->setRestaurant($this->restaurant())
            ->setName(trim((string) $data['name']))
            ->setSortOrder((int) ($data['sort_order'] ?? 0))
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $this->json(['message' => 'Menu category created.', 'category' => $this->serialize($category)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $category = $this->ownedCategory($id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validate($data, true);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        if (array_key_exists('name', $data)) {
            $category->setName(trim((string) $data['name']));
        }
        if (array_key_exists('sort_order', $data)) {
            $category->setSortOrder((int) $data['sort_order']);
        }
        $category->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Menu category updated.', 'category' => $this->serialize($category)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(string $id): JsonResponse
    {
        $category = $this->ownedCategory($id);
        if (!$category->getItems()->isEmpty()) {
            return $this->json(['message' => 'This category still contains menu items. Move or delete those items first.'], 409);
        }
        $this->entityManager->remove($category);
        $this->entityManager->flush();

        return $this->json(['message' => 'Menu category deleted.']);
    }

    private function ownedCategory(string $id): MenuCategory
    {
        $category = $this->entityManager->getRepository(MenuCategory::class)->createQueryBuilder('category')
            ->andWhere('category.id = :id')->setParameter('id', $id)
            ->andWhere('category.restaurant = :restaurant')->setParameter('restaurant', $this->restaurant())
            ->getQuery()->getOneOrNullResult();
        if (!$category instanceof MenuCategory) {
            throw $this->createNotFoundException('Menu category not found.');
        }

        return $category;
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, bool $partial = false): ?JsonResponse
    {
        $errors = [];
        if ((!$partial || array_key_exists('name', $data)) && '' === trim((string) ($data['name'] ?? ''))) {
            $errors['name'][] = 'A category name is required.';
        }
        if (isset($data['sort_order']) && (!is_numeric($data['sort_order']) || (int) $data['sort_order'] < 0)) {
            $errors['sort_order'][] = 'Sort order must be zero or greater.';
        }

        return [] === $errors ? null : $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
    }

    /** @return array<string, mixed> */
    private function serialize(MenuCategory $category): array
    {
        return ['id' => $category->getId(), 'name' => $category->getName(), 'sort_order' => $category->getSortOrder(), 'item_count' => $category->getItems()->count()];
    }
}
