<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\RiderPayout;
use App\Entity\User;
use App\Form\AdminPayoutPeriodFormType;
use App\Repository\AuditLogRepository;
use App\Repository\RiderEarningRepository;
use App\Repository\RiderPayoutRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the platform owes riders: admins generate a payout per rider for a period (the sum of
 * their earnings on orders delivered in it) and mark it paid once the money is sent.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/payout/rider')]
final class AdminRiderPayoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
        private readonly RiderPayoutRepository $payouts,
    ) {
    }

    #[Route(name: 'app_admin_rider_payout', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        return $this->renderList($request, $paginator);
    }

    /** Creates one pending payout per rider with earnings in the period, skipping riders already paid for an overlapping period. */
    #[Route('/generate', name: 'app_admin_rider_payout_generate', methods: ['POST'])]
    public function generate(Request $request, PaginatorInterface $paginator, RiderEarningRepository $earnings, #[CurrentUser] User $admin): Response
    {
        $form = $this->createForm(AdminPayoutPeriodFormType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderList($request, $paginator, $form);
        }
        /** @var \DateTimeImmutable $start */
        $start = $form->get('periodStart')->getData();
        /** @var \DateTimeImmutable $end */
        $end = $form->get('periodEnd')->getData();

        [$created, $skipped] = $this->entityManager->wrapInTransaction(function () use ($earnings, $admin, $start, $end): array {
            /** @var array<string, array{rider: Rider, total: string}> $totals */
            $totals = [];
            foreach ($earnings->findForOrdersDeliveredBetween($start->setTime(0, 0), $end->setTime(23, 59, 59)) as $earning) {
                $id = (string) $earning->getRider()->getId();
                $totals[$id] ??= ['rider' => $earning->getRider(), 'total' => '0.00'];
                $totals[$id]['total'] = bcadd($totals[$id]['total'], $earning->getTotalEarned(), 2);
            }
            $created = [];
            $skipped = 0;
            $now = new \DateTimeImmutable();
            foreach ($totals as $total) {
                if ($this->payouts->countOverlapping($total['rider'], $start, $end) > 0) {
                    ++$skipped;
                    continue;
                }
                $payout = (new RiderPayout())->setRider($total['rider'])->setPeriodStart($start)->setPeriodEnd($end)
                    ->setTotalAmount($total['total'])->setStatus(RiderPayout::STATUS_PENDING)
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $this->entityManager->persist($payout);
                $created[] = $payout;
            }
            $this->entityManager->flush();
            foreach ($created as $payout) {
                $this->audit->record($admin, 'rider_payout.generated', 'rider_payout', (string) $payout->getId(), [
                    'period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d'), 'total_amount' => $payout->getTotalAmount(),
                ]);
            }
            $this->entityManager->flush();

            return [count($created), $skipped];
        });

        if (0 === $created) {
            $this->addFlash('error', $skipped > 0 ? 'Every rider with earnings already has a payout overlapping this period.' : 'No rider earned anything in this period.');
        } else {
            $this->addFlash('success', sprintf('%d payout%s created%s.', $created, 1 === $created ? '' : 's', $skipped > 0 ? sprintf(', %d skipped for overlapping an earlier payout', $skipped) : ''));
        }

        return $this->redirectToRoute('app_admin_rider_payout', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/paid', name: 'app_admin_rider_payout_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markPaid(Request $request, RiderPayout $payout, #[CurrentUser] User $admin): Response
    {
        if (!$this->isCsrfTokenValid('payout'.$payout->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $this->entityManager->wrapInTransaction(function () use ($payout, $admin): void {
            $locked = $this->entityManager->find(RiderPayout::class, $payout->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof RiderPayout || RiderPayout::STATUS_PAID === $locked->getStatus()) {
                return;
            }
            $now = new \DateTimeImmutable();
            $locked->setStatus(RiderPayout::STATUS_PAID)->setPaidAt($now)->setUpdatedAt($now);
            $this->audit->record($admin, 'rider_payout.paid', 'rider_payout', (string) $locked->getId(), [
                'status' => ['from' => RiderPayout::STATUS_PENDING, 'to' => RiderPayout::STATUS_PAID],
                'paid_at' => $now->format(\DateTimeInterface::ATOM),
            ]);
            $this->entityManager->flush();
        });
        $this->addFlash('success', 'Payout marked paid.');

        return $this->redirectToRoute('app_admin_rider_payout', [], Response::HTTP_SEE_OTHER);
    }

    private function renderList(Request $request, PaginatorInterface $paginator, ?FormInterface $form = null): Response
    {
        $status = $request->query->getString('status');
        $status = in_array($status, RiderPayout::STATUSES, true) ? $status : null;

        return $this->render('admin_rider_payout/index.html.twig', [
            'payouts' => $paginator->paginate($this->payouts->createAdminListQuery($status), $request->query->getInt('page', 1), 25),
            'statuses' => RiderPayout::STATUSES,
            'status' => $status,
            'form' => $form ?? $this->createForm(AdminPayoutPeriodFormType::class),
        ], new Response(status: null !== $form ? 422 : 200));
    }
}
