<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\RiderAvailabilityStatus;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\RiderPoolService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider/pool', name: 'api_rider_pool_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderPoolController extends AbstractController
{
    public function __construct(private readonly RiderPoolService $pool)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || null === $user->getRider()) {
            return $this->json(['message' => 'Rider profile not found.'], 404);
        }
        $rider = $user->getRider();
        if (null === $rider->getCurrentLatitude() || null === $rider->getCurrentLongitude()) {
            return $this->json([
                'orders' => [],
                'reason' => 'Set your current location before viewing the rider pool.',
            ]);
        }
        if (RiderAvailabilityStatus::AVAILABLE !== $rider->getAvailabilityStatus()) {
            return $this->json([
                'orders' => [],
                'reason' => 'Go online to view available orders.',
            ]);
        }

        return $this->json(['orders' => $this->pool->visibleOrders($rider)]);
    }
}
