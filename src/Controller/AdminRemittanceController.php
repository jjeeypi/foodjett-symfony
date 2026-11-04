<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\RiderCashRemittanceRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Cash riders hand back from COD orders: admins confirm it arrived, which lowers the rider's cash on hand. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/remittance')]
final class AdminRemittanceController extends AbstractController
{
    #[Route(name: 'app_admin_remittance', methods: ['GET'])]
    public function index(Request $request, RiderCashRemittanceRepository $remittances, PaginatorInterface $paginator): Response
    {
        $status = $request->query->getString('status');
        $status = in_array($status, RiderCashRemittance::STATUSES, true) ? $status : null;

        return $this->render('admin_remittance/index.html.twig', [
            'remittances' => $paginator->paginate($remittances->createAdminListQuery($status), $request->query->getInt('page', 1), 25),
            'statuses' => RiderCashRemittance::STATUSES,
            'status' => $status,
        ]);
    }

    /** Confirming twice changes nothing: the rider's cash is lowered exactly once. */
    #[Route('/{id}/confirm', name: 'app_admin_remittance_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(Request $request, RiderCashRemittance $remittance, #[CurrentUser] User $admin, EntityManagerInterface $entityManager, AuditLogRepository $audit): Response
    {
        if (!$this->isCsrfTokenValid('remittance'.$remittance->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $audit, $remittance, $admin): void {
                $locked = $entityManager->find(RiderCashRemittance::class, $remittance->getId(), LockMode::PESSIMISTIC_WRITE) ?? throw new \DomainException('Remittance not found.');
                if (RiderCashRemittance::STATUS_CONFIRMED === $locked->getStatus()) {
                    return;
                }
                $rider = $entityManager->find(Rider::class, $locked->getRider()->getId(), LockMode::PESSIMISTIC_WRITE) ?? throw new \DomainException('Rider not found.');
                if (1 === bccomp($locked->getAmount(), $rider->getCashOnHand(), 2)) {
                    throw new \DomainException("The rider's cash on hand is lower than this remittance.");
                }
                $beforeCash = $rider->getCashOnHand();
                $afterCash = bcsub($beforeCash, $locked->getAmount(), 2);
                $now = new \DateTimeImmutable();
                $locked->setStatus(RiderCashRemittance::STATUS_CONFIRMED)->setConfirmedByAdmin($admin->getAdmin())
                    ->setRemittedAt($locked->getRemittedAt() ?? $now)->setUpdatedAt($now);
                $rider->setCashOnHand($afterCash)->setUpdatedAt($now);
                $audit->record($admin, 'rider_remittance.confirmed', 'rider_cash_remittance', (string) $locked->getId(), [
                    'status' => ['from' => RiderCashRemittance::STATUS_PENDING, 'to' => RiderCashRemittance::STATUS_CONFIRMED],
                    'rider_cash_on_hand' => ['from' => $beforeCash, 'to' => $afterCash],
                    'amount' => $locked->getAmount(),
                ]);
                $entityManager->flush();
            });
            $this->addFlash('success', 'Remittance confirmed.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_remittance', [], Response::HTTP_SEE_OTHER);
    }
}
