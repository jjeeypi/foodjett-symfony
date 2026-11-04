<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Favorite;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\RestaurantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Restaurants a customer saved with the heart button, and the button itself. */
#[IsGranted('ROLE_CUSTOMER')]
final class FavoriteController extends AbstractController
{
    #[Route('/favorites', name: 'app_favorites', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, FavoriteRepository $favorites): Response
    {
        $customer = $this->customer($user);

        return $this->render('favorite/index.html.twig', [
            'restaurants' => $favorites->findRestaurantsFor($customer),
            'favoriteIds' => $favorites->findRestaurantIdsFor($customer),
        ]);
    }

    /** Saves the restaurant, or removes it when it is already saved. */
    #[Route('/favorites/{slug}', name: 'app_favorite_toggle', methods: ['POST'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function toggle(
        string $slug,
        Request $request,
        #[CurrentUser] User $user,
        RestaurantRepository $restaurants,
        FavoriteRepository $favorites,
        EntityManagerInterface $entityManager,
    ): Response {
        $restaurant = $restaurants->findOneApprovedBySlug($slug) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('favorite', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Try again.');

            return $this->back($request, $slug);
        }

        $customer = $this->customer($user);
        $favorite = $favorites->findOneBy(['customer' => $customer, 'restaurant' => $restaurant]);
        if (null !== $favorite) {
            $entityManager->remove($favorite);
            $this->addFlash('success', $restaurant->getName().' was removed from your favorites.');
        } else {
            $entityManager->persist((new Favorite())->setCustomer($customer)->setRestaurant($restaurant)->setCreatedAt(new \DateTimeImmutable()));
            $this->addFlash('success', $restaurant->getName().' was saved to your favorites.');
        }
        $entityManager->flush();

        return $this->back($request, $slug);
    }

    /** Back to the page the heart was pressed on, when it is one of ours. */
    private function back(Request $request, string $slug): RedirectResponse
    {
        $back = $request->getPayload()->getString('back');
        if (str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('app_restaurant_menu', ['slug' => $slug]);
    }

    private function customer(User $user): Customer
    {
        return $user->getCustomer() ?? throw $this->createAccessDeniedException();
    }
}
