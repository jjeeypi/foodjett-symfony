<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Security\Voter\ApprovedAccountVoter;
use App\Security\Voter\OrderVoter;
use App\Service\OrderTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Orders waiting for a rider within the rider's search radius, with the pay on offer.
 * Accepting is first come, first served; declining hides the order for this rider.
 */
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
#[Route('/rider/pool')]
final class RiderPoolPageController extends AbstractController
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly OrderRepository $orders,
    ) {
    }

    #[Route('', name: 'app_rider_pool', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $rider = $this->rider($user);
        $offers = [];
        $reason = null;
        if (null === $rider->getCurrentLatitude() || null === $rider->getCurrentLongitude()) {
            $reason = 'Share your location on the dashboard to see orders near you.';
        } elseif (Rider::AVAILABILITY_AVAILABLE !== $rider->getAvailabilityStatus()) {
            $reason = 'Go online to see available orders.';
        } else {
            $offers = $this->transitions->visiblePoolOrders($rider);
            $reason = [] === $offers ? ($this->transitions->emptyPoolReason($rider)['message'] ?? 'No orders right now.') : null;
        }

        return $this->render('rider_pool_page/index.html.twig', ['offers' => $offers, 'reason' => $reason]);
    }

    #[Route('/{id}/accept', name: 'app_rider_pool_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->poolOrder($id, $request);
        if (Order::STATUS_FINDING_RIDER !== $order->getStatus() || null !== $order->getRider()) {
            $this->addFlash('error', 'This order was just taken by another rider.');

            return $this->redirectToRoute('app_rider_pool');
        }
        $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RIDER, $order);
        try {
            $this->transitions->acceptPoolOrder($order, $this->rider($user));
        } catch (HttpException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_rider_pool');
        } catch (\DomainException) {
            $this->addFlash('error', 'This order was just taken by another rider.');

            return $this->redirectToRoute('app_rider_pool');
        }
        $this->addFlash('success', 'Order accepted. Head to the restaurant.');

        return $this->redirectToRoute('app_rider_delivery');
    }

    #[Route('/{id}/decline', name: 'app_rider_pool_decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decline(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $order = $this->poolOrder($id, $request);
        if (Order::STATUS_FINDING_RIDER === $order->getStatus() && null === $order->getRider()) {
            $this->denyAccessUnlessGranted(OrderVoter::UPDATE_AS_RIDER, $order);
            try {
                $this->transitions->declinePoolOrder($order, $this->rider($user));
            } catch (HttpException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_rider_pool');
    }

    private function poolOrder(string $id, Request $request): Order
    {
        if (!$this->isCsrfTokenValid('pool'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        return $this->orders->find($id) ?? throw $this->createNotFoundException('Order not found.');
    }

    private function rider(User $user): Rider
    {
        return $user->getRider() ?? throw $this->createAccessDeniedException();
    }
}
