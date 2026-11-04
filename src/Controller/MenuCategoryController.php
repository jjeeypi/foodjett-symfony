<?php

namespace App\Controller;

use App\Entity\MenuCategory;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Form\MenuCategoryType;
use App\Repository\MenuCategoryRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The restaurant's menu sections. A section with items cannot be deleted. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
#[Route('/restaurant/menu/categories')]
final class MenuCategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MenuCategoryRepository $categories,
    ) {
    }

    #[Route(name: 'app_menu_category_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('menu_category/index.html.twig', [
            'menu_categories' => $this->categories->createForRestaurantQuery($this->restaurant($user))->getQuery()->getResult(),
        ]);
    }

    #[Route('/new', name: 'app_menu_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $menuCategory = new MenuCategory();
        $form = $this->createForm(MenuCategoryType::class, $menuCategory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $menuCategory->setRestaurant($this->restaurant($user))->setName(trim($menuCategory->getName()))
                ->setCreatedAt($now)->setUpdatedAt($now);
            $this->entityManager->persist($menuCategory);
            $this->entityManager->flush();
            $this->addFlash('success', 'Category added.');

            return $this->redirectToRoute('app_menu_category_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_category/new.html.twig', [
            'menu_category' => $menuCategory,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_menu_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $menuCategory = $this->owned($id, $user);
        $form = $this->createForm(MenuCategoryType::class, $menuCategory);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $menuCategory->setName(trim($menuCategory->getName()))->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', 'Category updated.');

            return $this->redirectToRoute('app_menu_category_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('menu_category/edit.html.twig', [
            'menu_category' => $menuCategory,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_menu_category_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $menuCategory = $this->owned($id, $user);
        if (!$this->isCsrfTokenValid('delete'.$menuCategory->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$menuCategory->getItems()->isEmpty()) {
            $this->addFlash('error', 'This category still has menu items. Move or delete them first.');
        } else {
            $this->entityManager->remove($menuCategory);
            $this->entityManager->flush();
            $this->addFlash('success', 'Category deleted.');
        }

        return $this->redirectToRoute('app_menu_category_index', [], Response::HTTP_SEE_OTHER);
    }

    private function owned(string $id, User $user): MenuCategory
    {
        return $this->categories->findOneForRestaurant($id, $this->restaurant($user))
            ?? throw $this->createNotFoundException('Menu category not found.');
    }

    private function restaurant(User $user): Restaurant
    {
        return $user->getRestaurant() ?? throw $this->createAccessDeniedException();
    }
}
