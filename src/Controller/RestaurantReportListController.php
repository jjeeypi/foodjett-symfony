<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrderReportRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Problems customers and riders reported about the restaurant, so it can learn from them. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantReportListController extends AbstractController
{
    #[Route('/restaurant/reports', name: 'app_restaurant_reports', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, OrderReportRepository $reports, PaginatorInterface $paginator): Response
    {
        $restaurant = $user->getRestaurant() ?? throw $this->createAccessDeniedException();

        return $this->render('restaurant_report_list/index.html.twig', [
            'reports' => $paginator->paginate($reports->createAgainstRestaurantQuery($restaurant), $request->query->getInt('page', 1), 20),
            'summary' => $reports->summarizeAgainstRestaurant($restaurant),
        ]);
    }
}
