<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Restaurant;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractRestaurantController extends AbstractController
{
    protected function restaurant(): Restaurant
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $restaurant = $user->getRestaurant();
        if (!$restaurant instanceof Restaurant) {
            throw $this->createNotFoundException('Restaurant profile not found.');
        }

        return $restaurant;
    }

    /** @return array<string, mixed>|JsonResponse */
    protected function body(Request $request): array|JsonResponse
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            try {
                return $request->toArray();
            } catch (\Throwable) {
                return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
            }
        }

        return $request->request->all();
    }
}
