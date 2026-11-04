<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\User;
use App\Form\LocationFormType;
use App\Message\RiderLocationUpdated;
use App\Repository\OrderRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rider's home: going online or offline, sharing the current location, and the delivery
 * in progress. A pending or rejected rider sees the application status instead.
 */
#[IsGranted('ROLE_RIDER')]
final class RiderDashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRepository $orders,
    ) {
    }

    #[Route('/rider', name: 'app_rider_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $rider = $this->rider($user);
        $approved = Rider::APPROVAL_APPROVED === $rider->getApprovalStatus();

        return $this->render('rider_dashboard/index.html.twig', [
            'rider' => $rider,
            'approved' => $approved,
            'active_order' => $approved ? $this->orders->findCurrentForRider($rider) : null,
            'location_form' => $approved ? $this->locationForm($rider) : null,
        ]);
    }

    #[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
    #[Route('/rider/availability', name: 'app_rider_availability', methods: ['POST'])]
    public function availability(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('availability', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $rider = $this->rider($user);
        if (null !== $this->orders->findCurrentForRider($rider)) {
            $this->addFlash('error', 'Finish your current delivery first.');

            return $this->redirectToRoute('app_rider_dashboard');
        }

        $online = Rider::AVAILABILITY_OFFLINE === $rider->getAvailabilityStatus();
        $rider->setAvailabilityStatus($online ? Rider::AVAILABILITY_AVAILABLE : Rider::AVAILABILITY_OFFLINE)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', $online ? 'You are online. New orders appear in the pool.' : 'You are offline.');

        return $this->redirectToRoute('app_rider_dashboard');
    }

    /** Saves the rider's position (at most every 5 seconds) and shares it with the customer during a delivery. */
    #[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
    #[Route('/rider/location', name: 'app_rider_location', methods: ['POST'])]
    public function location(Request $request, #[CurrentUser] User $user, MessageBusInterface $bus): Response
    {
        $rider = $this->rider($user);
        $form = $this->locationForm($rider)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Enter a latitude between -90 and 90 and a longitude between -180 and 180.');

            return $this->redirectToRoute('app_rider_dashboard');
        }

        $now = new \DateTimeImmutable();
        if (null !== $rider->getLastLocationAt() && $rider->getLastLocationAt() > $now->modify('-5 seconds')) {
            return $this->redirectToRoute('app_rider_dashboard');
        }
        $latitude = number_format((float) $form->get('latitude')->getData(), 7, '.', '');
        $longitude = number_format((float) $form->get('longitude')->getData(), 7, '.', '');
        $rider->setCurrentLatitude($latitude)->setCurrentLongitude($longitude)->setLastLocationAt($now)->setUpdatedAt($now);
        $this->entityManager->flush();

        $order = $this->orders->findCurrentForRider($rider);
        if (null !== $order) {
            $bus->dispatch(new RiderLocationUpdated(
                orderId: (string) $order->getId(),
                riderId: (string) $rider->getId(),
                latitude: $latitude,
                longitude: $longitude,
                occurredAt: $now,
            ));
        }
        $this->addFlash('success', 'Location updated.');

        return $this->redirectToRoute('app_rider_dashboard');
    }

    private function locationForm(Rider $rider): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(LocationFormType::class, [
            'latitude' => null === $rider->getCurrentLatitude() ? null : (float) $rider->getCurrentLatitude(),
            'longitude' => null === $rider->getCurrentLongitude() ? null : (float) $rider->getCurrentLongitude(),
        ], ['action' => $this->generateUrl('app_rider_location')]);
    }

    private function rider(User $user): Rider
    {
        return $user->getRider() ?? throw $this->createAccessDeniedException();
    }
}
