<?php

namespace App\Controller;

use App\Entity\MenuItem;
use App\Entity\MenuItemVariant;
use App\Entity\User;
use App\Form\MenuItemVariantType;
use App\Repository\MenuItemRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Options of one dish; they are listed on the dish page. Options in order history are kept. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
#[Route('/restaurant/menu/items/{itemId}/variants', requirements: ['itemId' => '\d+'])]
final class MenuItemVariantController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MenuItemRepository $items,
    ) {
    }

    #[Route('/new', name: 'app_menu_item_variant_new', methods: ['GET', 'POST'])]
    public function new(string $itemId, Request $request, #[CurrentUser] User $user): Response
    {
        $item = $this->item($itemId, $user);
        $menuItemVariant = new MenuItemVariant();
        $form = $this->createForm(MenuItemVariantType::class, $menuItemVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $menuItemVariant->setName(trim($menuItemVariant->getName()))->setPriceDelta(bcround($menuItemVariant->getPriceDelta(), 2))
                ->setCreatedAt($now)->setUpdatedAt($now);
            $item->addVariant($menuItemVariant);
            $this->entityManager->persist($menuItemVariant);
            $this->entityManager->flush();

            return $this->redirectToRoute('app_menu_item_show', ['id' => $itemId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_variant/new.html.twig', [
            'menu_item' => $item,
            'menu_item_variant' => $menuItemVariant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_item_variant_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(string $itemId, string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $item = $this->item($itemId, $user);
        $menuItemVariant = $this->variant($item, $id);
        $form = $this->createForm(MenuItemVariantType::class, $menuItemVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $menuItemVariant->setName(trim($menuItemVariant->getName()))->setPriceDelta(bcround($menuItemVariant->getPriceDelta(), 2))
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->redirectToRoute('app_menu_item_show', ['id' => $itemId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_variant/edit.html.twig', [
            'menu_item' => $item,
            'menu_item_variant' => $menuItemVariant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_item_variant_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(string $itemId, string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $item = $this->item($itemId, $user);
        $menuItemVariant = $this->variant($item, $id);
        if (!$this->isCsrfTokenValid('delete'.$menuItemVariant->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$menuItemVariant->getOrderItems()->isEmpty()) {
            $this->addFlash('error', 'This option is part of order history and cannot be deleted.');
        } else {
            $item->getVariants()->removeElement($menuItemVariant);
            $this->entityManager->remove($menuItemVariant);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_menu_item_show', ['id' => $itemId], Response::HTTP_SEE_OTHER);
    }

    private function item(string $itemId, User $user): MenuItem
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();

        return $this->items->findOneForRestaurant($itemId, $restaurant) ?? throw $this->createNotFoundException('Menu item not found.');
    }

    private function variant(MenuItem $item, string $id): MenuItemVariant
    {
        return $item->getVariants()->findFirst(static fn ($key, MenuItemVariant $variant): bool => (string) $variant->getId() === $id)
            ?? throw $this->createNotFoundException('Option not found.');
    }
}
