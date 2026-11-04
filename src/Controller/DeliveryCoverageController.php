<?php

namespace App\Controller;

use App\Repository\DeliveryZoneRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Answers the address picker's "do you deliver here?" while someone chooses a spot on the map. */
final class DeliveryCoverageController extends AbstractController
{
    #[Route('/delivery/coverage', name: 'app_delivery_coverage', methods: ['GET'])]
    public function index(Request $request, DeliveryZoneRepository $zones): JsonResponse
    {
        $latitude = $request->query->get('lat');
        $longitude = $request->query->get('lng');
        if (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return $this->json(['message' => 'Give lat and lng as numbers.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['covered' => $zones->isCovered((float) $latitude, (float) $longitude)]);
    }
}
