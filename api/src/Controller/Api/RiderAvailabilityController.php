<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\RiderAvailabilityStatus;
use App\Security\Voter\ApprovedAccountVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rider', name: 'api_rider_availability_')]
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderAvailabilityController extends AbstractRiderController
{
    #[Route('/availability', name: 'toggle', methods: ['PATCH'])]
    public function toggle(Request $request): JsonResponse
    {
        $rider = $this->rider();
        $activeOrder = $this->activeOrder(false);
        if (null !== $activeOrder && RiderAvailabilityStatus::OFFLINE !== $rider->getAvailabilityStatus()) {
            return $this->json(['message' => 'Finish your current delivery first.'], 409);
        }
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        [$latitude, $longitude, $errors] = $this->coordinates($data, false);
        if ([] !== $errors) {
            return $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
        }
        $now = new \DateTimeImmutable();
        if (RiderAvailabilityStatus::OFFLINE === $rider->getAvailabilityStatus()) {
            $rider->setAvailabilityStatus(RiderAvailabilityStatus::AVAILABLE);
            if (null !== $latitude && null !== $longitude) {
                $rider->setCurrentLatitude($latitude)->setCurrentLongitude($longitude)->setLastLocationAt($now);
            }
        } else {
            $rider->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE);
        }
        $rider->setUpdatedAt($now);
        $this->entityManager->flush();

        return $this->json([
            'availability_status' => $rider->getAvailabilityStatus()->value,
            'latitude' => $rider->getCurrentLatitude(), 'longitude' => $rider->getCurrentLongitude(),
            'last_location_at' => $rider->getLastLocationAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
