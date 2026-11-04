<?php

namespace App\Controller;

use App\Entity\MenuCategory;
use App\Form\MenuCategoryType;
use App\Repository\MenuCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/menu/category')]
final class MenuCategoryController extends AbstractController
{
    #[Route(name: 'app_menu_category_index', methods: ['GET'])]
    public function index(MenuCategoryRepository $menuCategoryRepository): Response
    {
        return $this->render('menu_category/index.html.twig', [
            'menu_categories' => $menuCategoryRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_menu_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $menuCategory = new MenuCategory();
        $form = $this->createForm(MenuCategoryType::class, $menuCategory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($menuCategory);
            $entityManager->flush();

            return $this->redirectToRoute('app_menu_category_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_category/new.html.twig', [
            'menu_category' => $menuCategory,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_category_show', methods: ['GET'])]
    public function show(MenuCategory $menuCategory): Response
    {
        return $this->render('menu_category/show.html.twig', [
            'menu_category' => $menuCategory,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_category_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, MenuCategory $menuCategory, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(MenuCategoryType::class, $menuCategory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_menu_category_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_category/edit.html.twig', [
            'menu_category' => $menuCategory,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_category_delete', methods: ['POST'])]
    public function delete(Request $request, MenuCategory $menuCategory, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$menuCategory->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($menuCategory);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_menu_category_index', [], Response::HTTP_SEE_OTHER);
    }
}
