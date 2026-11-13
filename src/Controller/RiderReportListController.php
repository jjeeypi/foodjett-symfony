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

/** Problems customers and restaurants reported about the rider's deliveries. */
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderReportListController extends AbstractController
{
    #[Route('/rider/reports', name: 'app_rider_reports', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, OrderReportRepository $reports, PaginatorInterface $paginator): Response
    {
        $rider = $user->getRider() ?? throw $this->createAccessDeniedException();

        return $this->render('rider_report_list/index.html.twig', [
            'reports' => $paginator->paginate($reports->createAgainstRiderQuery($rider), $request->query->getInt('page', 1), 20),
            'summary' => $reports->summarizeAgainstRider($rider),
        ]);
    }
}
