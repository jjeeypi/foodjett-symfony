<?php

namespace App\Controller;

use App\Entity\MenuItemAddon;
use App\Form\MenuItemAddonType;
use App\Repository\MenuItemAddonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/menu/item/addon')]
final class MenuItemAddonController extends AbstractController
{
    #[Route(name: 'app_menu_item_addon_index', methods: ['GET'])]
    public function index(MenuItemAddonRepository $menuItemAddonRepository): Response
    {
        return $this->render('menu_item_addon/index.html.twig', [
            'menu_item_addons' => $menuItemAddonRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_menu_item_addon_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $menuItemAddon = new MenuItemAddon();
        $form = $this->createForm(MenuItemAddonType::class, $menuItemAddon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($menuItemAddon);
            $entityManager->flush();

            return $this->redirectToRoute('app_menu_item_addon_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_addon/new.html.twig', [
            'menu_item_addon' => $menuItemAddon,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_item_addon_show', methods: ['GET'])]
    public function show(MenuItemAddon $menuItemAddon): Response
    {
        return $this->render('menu_item_addon/show.html.twig', [
            'menu_item_addon' => $menuItemAddon,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_item_addon_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, MenuItemAddon $menuItemAddon, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(MenuItemAddonType::class, $menuItemAddon);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_menu_item_addon_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_addon/edit.html.twig', [
            'menu_item_addon' => $menuItemAddon,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_item_addon_delete', methods: ['POST'])]
    public function delete(Request $request, MenuItemAddon $menuItemAddon, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$menuItemAddon->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($menuItemAddon);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_menu_item_addon_index', [], Response::HTTP_SEE_OTHER);
    }
}
