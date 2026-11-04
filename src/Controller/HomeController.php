<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Repository\FavoriteRepository;
use App\Repository\RestaurantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The explore page: a welcome, the craving shortcuts and the restaurants worth trying first. */
final class HomeController extends AbstractController
{
    private const SHOWN = 9;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request, RestaurantRepository $restaurants, FavoriteRepository $favorites): Response
    {
        $craving = $request->query->getString('craving');
        $craving = isset(Restaurant::CRAVINGS[$craving]) ? $craving : '';

        $matches = array_values(array_filter(
            $restaurants->findApproved(),
            static fn (Restaurant $restaurant): bool => '' === $craving || $restaurant->matchesCraving($craving),
        ));
        // Open places first, then the best rated, then by name.
        usort($matches, static fn (Restaurant $a, Restaurant $b): int => [$b->isOpenNow(), $b->getAverageRating(), $a->getName()]
            <=> [$a->isOpenNow(), $a->getAverageRating(), $b->getName()]);

        return $this->render('home/index.html.twig', [
            'cravings' => Restaurant::CRAVINGS,
            'craving' => $craving,
            'restaurants' => array_slice($matches, 0, self::SHOWN),
            'total' => count($matches),
            'favoriteIds' => $favorites->findRestaurantIdsFor($this->getUser() instanceof User ? $this->getUser()->getCustomer() : null),
        ]);
    }
}
