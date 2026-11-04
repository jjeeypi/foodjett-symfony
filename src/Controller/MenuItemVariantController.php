<?php

namespace App\Controller;

use App\Entity\MenuItemVariant;
use App\Form\MenuItemVariantType;
use App\Repository\MenuItemVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/menu/item/variant')]
final class MenuItemVariantController extends AbstractController
{
    #[Route(name: 'app_menu_item_variant_index', methods: ['GET'])]
    public function index(MenuItemVariantRepository $menuItemVariantRepository): Response
    {
        return $this->render('menu_item_variant/index.html.twig', [
            'menu_item_variants' => $menuItemVariantRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_menu_item_variant_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $menuItemVariant = new MenuItemVariant();
        $form = $this->createForm(MenuItemVariantType::class, $menuItemVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($menuItemVariant);
            $entityManager->flush();

            return $this->redirectToRoute('app_menu_item_variant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_variant/new.html.twig', [
            'menu_item_variant' => $menuItemVariant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_item_variant_show', methods: ['GET'])]
    public function show(MenuItemVariant $menuItemVariant): Response
    {
        return $this->render('menu_item_variant/show.html.twig', [
            'menu_item_variant' => $menuItemVariant,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_item_variant_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, MenuItemVariant $menuItemVariant, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(MenuItemVariantType::class, $menuItemVariant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_menu_item_variant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_item_variant/edit.html.twig', [
            'menu_item_variant' => $menuItemVariant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_item_variant_delete', methods: ['POST'])]
    public function delete(Request $request, MenuItemVariant $menuItemVariant, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$menuItemVariant->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($menuItemVariant);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_menu_item_variant_index', [], Response::HTTP_SEE_OTHER);
    }
}
