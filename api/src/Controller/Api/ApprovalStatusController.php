<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Voter\ApprovedAccountVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApprovalStatusController extends AbstractController
{
    #[Route('/api/restaurant/approval-status', name: 'api_restaurant_approval_status', methods: ['GET'])]
    #[IsGranted('ROLE_RESTAURANT')]
    public function restaurantStatus(): JsonResponse
    {
        $user = $this->authenticatedUser();
        $restaurant = $user->getRestaurant();

        if (null === $restaurant) {
            return $this->json(['message' => 'Restaurant profile not found.'], 404);
        }

        return $this->json([
            'approval_status' => $restaurant->getApprovalStatus()->value,
            'rejection_reason' => $restaurant->getRejectionReason(),
        ]);
    }

    #[Route('/api/rider/approval-status', name: 'api_rider_approval_status', methods: ['GET'])]
    #[IsGranted('ROLE_RIDER')]
    public function riderStatus(): JsonResponse
    {
        $user = $this->authenticatedUser();
        $rider = $user->getRider();

        if (null === $rider) {
            return $this->json(['message' => 'Rider profile not found.'], 404);
        }

        return $this->json([
            'approval_status' => $rider->getApprovalStatus()->value,
            'rejection_reason' => $rider->getRejectionReason(),
        ]);
    }

    #[Route('/api/restaurant/dashboard', name: 'api_restaurant_dashboard_probe', methods: ['GET'])]
    #[IsGranted('ROLE_RESTAURANT')]
    #[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
    public function restaurantDashboard(): JsonResponse
    {
        return $this->json(['message' => 'Restaurant account is approved.']);
    }

    #[Route('/api/rider/pool', name: 'api_rider_pool_probe', methods: ['GET'])]
    #[IsGranted('ROLE_RIDER')]
    #[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
    public function riderPool(): JsonResponse
    {
        return $this->json(['message' => 'Rider account is approved.']);
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
