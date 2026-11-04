<?php

namespace App\Controller;

use App\Entity\OrderReport;
use App\Entity\User;
use App\Form\AdminReasonFormType;
use App\Repository\AuditLogRepository;
use App\Repository\OrderReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Problems reported on orders: admins resolve or reject each one with a written outcome. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/report')]
final class AdminOrderReportController extends AbstractController
{
    public function __construct(
        private readonly OrderReportRepository $reports,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route(name: 'app_admin_order_report', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderList($request);
    }

    /** Resolves or rejects a report, depending on which button was pressed. */
    #[Route('/{id}', name: 'app_admin_order_report_decide', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(Request $request, OrderReport $report, #[CurrentUser] User $admin, EntityManagerInterface $entityManager, AuditLogRepository $audit): Response
    {
        $form = $this->decisionForm($report)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderList($request, $report, $form);
        }
        if (in_array($report->getStatus(), [OrderReport::STATUS_RESOLVED, OrderReport::STATUS_REJECTED], true)) {
            $this->addFlash('error', 'This report is already closed.');

            return $this->redirectToRoute('app_admin_order_report', [], Response::HTTP_SEE_OTHER);
        }
        $status = OrderReport::STATUS_REJECTED === $request->getPayload()->getString('decision') ? OrderReport::STATUS_REJECTED : OrderReport::STATUS_RESOLVED;
        $resolution = trim((string) $form->get('reason')->getData());
        $before = $report->getStatus();
        $now = new \DateTimeImmutable();
        $report->setStatus($status)->setResolution($resolution)->setResolvedByAdmin($admin->getAdmin())->setResolvedAt($now)->setUpdatedAt($now);
        $audit->record($admin, 'order_report.'.$status, 'order_report', (string) $report->getId(), [
            'status' => ['from' => $before, 'to' => $status],
            'resolution' => $resolution,
        ]);
        $entityManager->flush();
        $this->addFlash('success', 'Report '.$status.'.');

        return $this->redirectToRoute('app_admin_order_report', [], Response::HTTP_SEE_OTHER);
    }

    private function renderList(Request $request, ?OrderReport $failed = null, ?FormInterface $failedForm = null): Response
    {
        $filters = [];
        foreach (['status' => OrderReport::STATUSES, 'type' => OrderReport::TYPES, 'against' => OrderReport::AGAINST_TARGETS] as $name => $allowed) {
            $value = $request->query->getString($name);
            $filters[$name] = in_array($value, $allowed, true) ? $value : null;
        }
        $reports = $this->paginator->paginate($this->reports->createAdminListQuery($filters['status'], $filters['type'], $filters['against']), $request->query->getInt('page', 1), 25);
        $forms = [];
        foreach ($reports as $report) {
            if (!in_array($report->getStatus(), [OrderReport::STATUS_RESOLVED, OrderReport::STATUS_REJECTED], true)) {
                $forms[$report->getId()] = ($report === $failed ? $failedForm : $this->decisionForm($report))->createView();
            }
        }

        return $this->render('admin_order_report/index.html.twig', [
            'reports' => $reports,
            'forms' => $forms,
            'filters' => $filters,
            'choices' => ['status' => OrderReport::STATUSES, 'type' => OrderReport::TYPES, 'against' => OrderReport::AGAINST_TARGETS],
        ], new Response(status: null !== $failedForm ? 422 : 200));
    }

    private function decisionForm(OrderReport $report): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('report_'.$report->getId(), AdminReasonFormType::class, null, [
            'reason_label' => 'Outcome (the reporter sees this)',
        ]);
    }
}
