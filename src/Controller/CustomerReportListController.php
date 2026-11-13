<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrderReportRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The problems a customer reported on their orders, with where each stands and the admin's decision. */
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerReportListController extends AbstractController
{
    #[Route('/reports', name: 'app_customer_reports', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, OrderReportRepository $reports, PaginatorInterface $paginator): Response
    {
        return $this->render('customer_report_list/index.html.twig', [
            'reports' => $paginator->paginate($reports->createForReporterQuery($user), $request->query->getInt('page', 1), 20),
        ]);
    }
}
