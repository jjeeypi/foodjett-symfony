<?php

namespace App\Controller;

use App\Entity\MenuItem;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Form\MenuItemType;
use App\Repository\MenuCategoryRepository;
use App\Repository\MenuItemRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The restaurant's dishes. A dish that was ever ordered is never deleted: it is marked
 * unavailable instead, so order history keeps pointing at it.
 */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
#[Route('/restaurant/menu/items')]
final class MenuItemController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MenuItemRepository $items,
        private readonly MenuCategoryRepository $categories,
        #[Autowire('%app.public_upload_dir%')]
        private readonly string $publicUploadDir,
    ) {
    }

    #[Route(name: 'app_menu_item_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('menu_item/index.html.twig', [
            'menu_items' => $this->items->createForRestaurantQuery($this->restaurant($user))->getQuery()->getResult(),
            'menu_categories' => $this->categoryChoices($user),
        ]);
    }

    #[Route('/new', name: 'app_menu_item_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $categories = $this->categoryChoices($user);
        if ([] === $categories) {
            $this->addFlash('error', 'Add a menu category first.');

            return $this->redirectToRoute('app_menu_category_new');
        }
        $menuItem = new MenuItem();
        $form = $this->createForm(MenuItemType::class, $menuItem, ['categories' => $categories]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $menuItem->setCreatedAt($now)->setUpdatedAt($now);
            $this->normalize($menuItem, $form->get('photo')->getData());
            $this->entityManager->persist($menuItem);
            $this->entityManager->flush();
            $this->addFlash('success', 'Menu item added.');

            return $this->redirectToRoute('app_menu_item_show', ['id' => $menuItem->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item/new.html.twig', [
            'menu_item' => $menuItem,
            'form' => $form,
        ]);
    }

    /** The dish with its options and add-ons. */
    #[Route('/{id}', name: 'app_menu_item_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $id, #[CurrentUser] User $user): Response
    {
        return $this->render('menu_item/show.html.twig', [
            'menu_item' => $this->owned($id, $user),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_item_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $menuItem = $this->owned($id, $user);
        $form = $this->createForm(MenuItemType::class, $menuItem, ['categories' => $this->categoryChoices($user)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $menuItem->setUpdatedAt(new \DateTimeImmutable());
            $this->normalize($menuItem, $form->get('photo')->getData());
            $this->entityManager->flush();
            $this->addFlash('success', 'Menu item updated.');

            return $this->redirectToRoute('app_menu_item_show', ['id' => $menuItem->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item/edit.html.twig', [
            'menu_item' => $menuItem,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/availability', name: 'app_menu_item_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $menuItem = $this->owned($id, $user);
        if (!$this->isCsrfTokenValid('toggle'.$menuItem->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $menuItem->setIsAvailable(!$menuItem->isAvailable())->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%s is now %s.', $menuItem->getName(), $menuItem->isAvailable() ? 'available' : 'unavailable'));

        return $this->redirectToRoute('app_menu_item_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'app_menu_item_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $menuItem = $this->owned($id, $user);
        if (!$this->isCsrfTokenValid('delete'.$menuItem->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$menuItem->getOrderItems()->isEmpty()) {
            $menuItem->setIsAvailable(false)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('error', 'This item has order history, so it was marked unavailable instead of deleted.');

            return $this->redirectToRoute('app_menu_item_index', [], Response::HTTP_SEE_OTHER);
        }

        $photo = $menuItem->getPhotoPath();
        foreach ($menuItem->getVariants()->toArray() as $variant) {
            $this->entityManager->remove($variant);
        }
        foreach ($menuItem->getAddons()->toArray() as $addon) {
            $this->entityManager->remove($addon);
        }
        $this->entityManager->remove($menuItem);
        $this->entityManager->flush();
        $this->deletePublicFile($photo);
        $this->addFlash('success', 'Menu item deleted.');

        return $this->redirectToRoute('app_menu_item_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Trims text, rounds the price to centavos and stores a new photo. */
    private function normalize(MenuItem $item, ?UploadedFile $photo): void
    {
        $description = trim((string) $item->getDescription());
        $item->setName(trim($item->getName()))
            ->setDescription('' === $description ? null : $description)
            ->setBasePrice(bcround($item->getBasePrice(), 2));
        if (null !== $photo) {
            $this->deletePublicFile($item->getPhotoPath());
            $filename = bin2hex(random_bytes(20)).'.'.($photo->guessExtension() ?: 'bin');
            $photo->move($this->publicUploadDir.'/restaurants/menu-items', $filename);
            $item->setPhotoPath('uploads/restaurants/menu-items/'.$filename);
        }
    }

    private function deletePublicFile(?string $path): void
    {
        if (null !== $path && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
            @unlink($this->publicUploadDir.'/'.substr($path, strlen('uploads/')));
        }
    }

    /** @return list<\App\Entity\MenuCategory> */
    private function categoryChoices(User $user): array
    {
        return $this->categories->createForRestaurantQuery($this->restaurant($user))->getQuery()->getResult();
    }

    private function owned(string $id, User $user): MenuItem
    {
        return $this->items->findOneForRestaurant($id, $this->restaurant($user))
            ?? throw $this->createNotFoundException('Menu item not found.');
    }

    private function restaurant(User $user): Restaurant
    {
        return $user->getRestaurant() ?? throw $this->createAccessDeniedException();
    }
}
