<?php

namespace App\Controller;

use App\Repository\RestaurantRepository;
use App\Repository\RestaurantReviewRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** A restaurant's public page: details, weekly hours, menu by category and reviews. */
final class RestaurantMenuController extends AbstractController
{
    #[Route('/restaurants/{slug}', name: 'app_restaurant_menu', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function index(
        string $slug,
        Request $request,
        RestaurantRepository $restaurants,
        RestaurantReviewRepository $reviews,
        PaginatorInterface $paginator,
    ): Response {
        $restaurant = $restaurants->findOneApprovedBySlug($slug)
            ?? throw $this->createNotFoundException('Restaurant not found.');

        return $this->render('restaurant_menu/index.html.twig', [
            'restaurant' => $restaurant,
            'reviews' => $paginator->paginate($reviews->createForRestaurantQuery($restaurant), $request->query->getInt('page', 1), 10),
            'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ]);
    }
}
