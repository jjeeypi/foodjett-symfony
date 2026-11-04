<?php

namespace App\Controller;

use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\User;
use App\Form\MenuItemAddonType;
use App\Repository\MenuItemRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Add-ons of one dish; they are listed on the dish page. Add-ons in order history are kept. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
#[Route('/restaurant/menu/items/{itemId}/addons', requirements: ['itemId' => '\d+'])]
final class MenuItemAddonController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MenuItemRepository $items,
    ) {
    }

    #[Route('/new', name: 'app_menu_item_addon_new', methods: ['GET', 'POST'])]
    public function new(string $itemId, Request $request, #[CurrentUser] User $user): Response
    {
        $item = $this->item($itemId, $user);
        $menuItemAddon = new MenuItemAddon();
        $form = $this->createForm(MenuItemAddonType::class, $menuItemAddon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $menuItemAddon->setName(trim($menuItemAddon->getName()))->setPrice(bcround($menuItemAddon->getPrice(), 2))
                ->setCreatedAt($now)->setUpdatedAt($now);
            $item->addAddon($menuItemAddon);
            $this->entityManager->persist($menuItemAddon);
            $this->entityManager->flush();

            return $this->redirectToRoute('app_menu_item_show', ['id' => $itemId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_addon/new.html.twig', [
            'menu_item' => $item,
            'menu_item_addon' => $menuItemAddon,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_item_addon_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(string $itemId, string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $item = $this->item($itemId, $user);
        $menuItemAddon = $this->addon($item, $id);
        $form = $this->createForm(MenuItemAddonType::class, $menuItemAddon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $menuItemAddon->setName(trim($menuItemAddon->getName()))->setPrice(bcround($menuItemAddon->getPrice(), 2))
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->redirectToRoute('app_menu_item_show', ['id' => $itemId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_addon/edit.html.twig', [
            'menu_item' => $item,
            'menu_item_addon' => $menuItemAddon,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_item_addon_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(string $itemId, string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $item = $this->item($itemId, $user);
        $menuItemAddon = $this->addon($item, $id);
        if (!$this->isCsrfTokenValid('delete'.$menuItemAddon->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$menuItemAddon->getOrderItemAddons()->isEmpty()) {
            $this->addFlash('error', 'This add-on is part of order history and cannot be deleted. Mark it unavailable instead.');
        } else {
            $item->getAddons()->removeElement($menuItemAddon);
            $this->entityManager->remove($menuItemAddon);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_menu_item_show', ['id' => $itemId], Response::HTTP_SEE_OTHER);
    }

    private function item(string $itemId, User $user): MenuItem
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();

        return $this->items->findOneForRestaurant($itemId, $restaurant) ?? throw $this->createNotFoundException('Menu item not found.');
    }

    private function addon(MenuItem $item, string $id): MenuItemAddon
    {
        return $item->getAddons()->findFirst(static fn ($key, MenuItemAddon $addon): bool => (string) $addon->getId() === $id)
            ?? throw $this->createNotFoundException('Add-on not found.');
    }
}
