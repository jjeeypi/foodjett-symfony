<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\RiderEarningRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rider's home: today at a glance (deliveries, earnings, cash on hand), the delivery in
 * progress and the latest deliveries. A pending or rejected rider sees the application status instead.
 */
#[IsGranted('ROLE_RIDER')]
final class RiderDashboardController extends AbstractController
{
    #[Route('/rider', name: 'app_rider_dashboard', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, OrderRepository $orders, RiderEarningRepository $earnings): Response
    {
        $rider = $user->getRider() ?? throw $this->createAccessDeniedException();
        $approved = Rider::APPROVAL_APPROVED === $rider->getApprovalStatus();
        $today = (new \DateTimeImmutable())->setTime(0, 0);

        return $this->render('rider_dashboard/index.html.twig', [
            'rider' => $rider,
            'approved' => $approved,
            'active_order' => $approved ? $orders->findCurrentForRider($rider) : null,
            'stats' => $approved ? [
                'deliveries_today' => $earnings->countByRiderSince($rider, $today),
                'earned_today' => bcround($earnings->sumEarnedByRiderSince($rider, $today), 2),
                'earned_week' => bcround($earnings->sumEarnedByRiderSince($rider, $today->modify('monday this week')), 2),
                'cash_at_limit' => -1 !== bccomp($rider->getCashOnHand(), $rider->getCashRemitLimit(), 2),
                'recent' => $earnings->createForRiderQuery($rider)->setMaxResults(5)->getQuery()->getResult(),
            ] : null,
        ]);
    }
}
