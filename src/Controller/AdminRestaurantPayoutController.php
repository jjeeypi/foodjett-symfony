<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\RestaurantPayout;
use App\Entity\User;
use App\Form\AdminPayoutPeriodFormType;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Repository\RestaurantPayoutRepository;
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
 * What the platform owes restaurants: admins generate a payout per restaurant for a period
 * (food sales minus commission on delivered orders) and mark it paid once the money is sent.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/payout/restaurant')]
final class AdminRestaurantPayoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
        private readonly RestaurantPayoutRepository $payouts,
    ) {
    }

    #[Route(name: 'app_admin_restaurant_payout', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        return $this->renderList($request, $paginator);
    }

    /** Creates one pending payout per restaurant with deliveries in the period, skipping restaurants already paid for an overlapping period. */
    #[Route('/generate', name: 'app_admin_restaurant_payout_generate', methods: ['POST'])]
    public function generate(Request $request, PaginatorInterface $paginator, OrderRepository $orders, #[CurrentUser] User $admin): Response
    {
        $form = $this->createForm(AdminPayoutPeriodFormType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderList($request, $paginator, $form);
        }
        /** @var \DateTimeImmutable $start */
        $start = $form->get('periodStart')->getData();
        /** @var \DateTimeImmutable $end */
        $end = $form->get('periodEnd')->getData();

        [$created, $skipped] = $this->entityManager->wrapInTransaction(function () use ($orders, $admin, $start, $end): array {
            /** @var array<string, array{restaurant: Restaurant, gross: string, commission: string}> $totals */
            $totals = [];
            foreach ($orders->findDeliveredBetween($start->setTime(0, 0), $end->setTime(23, 59, 59)) as $order) {
                $id = (string) $order->getRestaurant()->getId();
                $totals[$id] ??= ['restaurant' => $order->getRestaurant(), 'gross' => '0.00', 'commission' => '0.00'];
                $totals[$id]['gross'] = bcadd($totals[$id]['gross'], $order->getSubtotal(), 2);
                $totals[$id]['commission'] = bcadd($totals[$id]['commission'], $order->getCommissionAmount() ?? '0.00', 2);
            }
            $created = [];
            $skipped = 0;
            $now = new \DateTimeImmutable();
            foreach ($totals as $total) {
                if ($this->payouts->countOverlapping($total['restaurant'], $start, $end) > 0) {
                    ++$skipped;
                    continue;
                }
                $payout = (new RestaurantPayout())->setRestaurant($total['restaurant'])->setPeriodStart($start)->setPeriodEnd($end)
                    ->setGrossSales($total['gross'])->setCommissionDeducted($total['commission'])
                    ->setNetAmount(bcsub($total['gross'], $total['commission'], 2))->setStatus(RestaurantPayout::STATUS_PENDING)
                    ->setCreatedAt($now)->setUpdatedAt($now);
                $this->entityManager->persist($payout);
                $created[] = $payout;
            }
            $this->entityManager->flush();
            foreach ($created as $payout) {
                $this->audit->record($admin, 'restaurant_payout.generated', 'restaurant_payout', (string) $payout->getId(), [
                    'period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d'),
                    'gross_sales' => $payout->getGrossSales(), 'commission_deducted' => $payout->getCommissionDeducted(), 'net_amount' => $payout->getNetAmount(),
                ]);
            }
            $this->entityManager->flush();

            return [count($created), $skipped];
        });

        if (0 === $created) {
            $this->addFlash('error', $skipped > 0 ? 'Every restaurant with deliveries already has a payout overlapping this period.' : 'No restaurant had a delivered order in this period.');
        } else {
            $this->addFlash('success', sprintf('%d payout%s created%s.', $created, 1 === $created ? '' : 's', $skipped > 0 ? sprintf(', %d skipped for overlapping an earlier payout', $skipped) : ''));
        }

        return $this->redirectToRoute('app_admin_restaurant_payout', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/paid', name: 'app_admin_restaurant_payout_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markPaid(Request $request, RestaurantPayout $payout, #[CurrentUser] User $admin): Response
    {
        if (!$this->isCsrfTokenValid('payout'.$payout->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $this->entityManager->wrapInTransaction(function () use ($payout, $admin): void {
            $locked = $this->entityManager->find(RestaurantPayout::class, $payout->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof RestaurantPayout || RestaurantPayout::STATUS_PAID === $locked->getStatus()) {
                return;
            }
            $now = new \DateTimeImmutable();
            $locked->setStatus(RestaurantPayout::STATUS_PAID)->setPaidAt($now)->setUpdatedAt($now);
            $this->audit->record($admin, 'restaurant_payout.paid', 'restaurant_payout', (string) $locked->getId(), [
                'status' => ['from' => RestaurantPayout::STATUS_PENDING, 'to' => RestaurantPayout::STATUS_PAID],
                'paid_at' => $now->format(\DateTimeInterface::ATOM),
            ]);
            $this->entityManager->flush();
        });
        $this->addFlash('success', 'Payout marked paid.');

        return $this->redirectToRoute('app_admin_restaurant_payout', [], Response::HTTP_SEE_OTHER);
    }

    private function renderList(Request $request, PaginatorInterface $paginator, ?FormInterface $form = null): Response
    {
        $status = $request->query->getString('status');
        $status = in_array($status, RestaurantPayout::STATUSES, true) ? $status : null;

        return $this->render('admin_restaurant_payout/index.html.twig', [
            'payouts' => $paginator->paginate($this->payouts->createAdminListQuery($status), $request->query->getInt('page', 1), 25),
            'statuses' => RestaurantPayout::STATUSES,
            'status' => $status,
            'form' => $form ?? $this->createForm(AdminPayoutPeriodFormType::class),
        ], new Response(status: null !== $form ? 422 : 200));
    }
}
