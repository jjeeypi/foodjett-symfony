<?php

namespace App\Controller;

use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\User;
use App\Form\RemittanceFormType;
use App\Repository\RiderCashRemittanceRepository;
use App\Repository\RiderEarningRepository;
use App\Repository\RiderPayoutRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Earnings (today, this week, this month and per delivery), payouts, and the COD cash the
 * rider holds, with a request to remit it. A request may not exceed the cash still
 * available after earlier pending requests.
 */
#[IsGranted('ROLE_RIDER')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your rider account is awaiting approval.')]
final class RiderFinancePageController extends AbstractController
{
    #[Route('/rider/finance', name: 'app_rider_finance', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $entityManager,
        RiderEarningRepository $earnings,
        RiderPayoutRepository $payouts,
        RiderCashRemittanceRepository $remittances,
        PaginatorInterface $paginator,
    ): Response {
        $rider = $user->getRider() ?? throw $this->createAccessDeniedException();
        $remittance = new RiderCashRemittance();
        $form = $this->createForm(RemittanceFormType::class, $remittance);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $amount = bcround($remittance->getAmount(), 2);
            $reference = trim((string) $remittance->getReferenceNote());
            try {
                $entityManager->wrapInTransaction(function () use ($entityManager, $remittances, $rider, $remittance, $amount, $reference): void {
                    $locked = $entityManager->find(Rider::class, $rider->getId(), LockMode::PESSIMISTIC_WRITE);
                    $available = bcsub($locked->getCashOnHand(), bcround($remittances->sumPendingForRider($locked), 2), 2);
                    if (1 === bccomp($amount, $available, 2)) {
                        throw new \DomainException('The amount is more than the cash you hold after your pending requests.');
                    }
                    $now = new \DateTimeImmutable();
                    $remittance->setRider($locked)->setAmount($amount)->setReferenceNote('' === $reference ? null : $reference)
                        ->setStatus(RiderCashRemittance::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
                    $entityManager->persist($remittance);
                    $entityManager->flush();
                });
                $this->addFlash('success', 'Remittance request sent. An admin confirms it once the cash arrives.');

                return $this->redirectToRoute('app_rider_finance', [], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('app_rider_finance', [], Response::HTTP_SEE_OTHER);
            }
        }

        $today = (new \DateTimeImmutable())->setTime(0, 0);
        $pending = bcround($remittances->sumPendingForRider($rider), 2);
        $available = bcsub($rider->getCashOnHand(), $pending, 2);

        return $this->render('rider_finance_page/index.html.twig', [
            'rider' => $rider,
            'totals' => [
                'Today' => bcround($earnings->sumEarnedByRiderSince($rider, $today), 2),
                'This week' => bcround($earnings->sumEarnedByRiderSince($rider, $today->modify('monday this week')), 2),
                'This month' => bcround($earnings->sumEarnedByRiderSince($rider, $today->modify('first day of this month')), 2),
            ],
            'cash' => [
                'pending' => $pending,
                'available' => -1 === bccomp($available, '0', 2) ? '0.00' : $available,
                'at_limit' => -1 !== bccomp($rider->getCashOnHand(), $rider->getCashRemitLimit(), 2),
            ],
            'earnings' => $paginator->paginate($earnings->createForRiderQuery($rider), $request->query->getInt('page', 1), 20),
            'payouts' => $payouts->createForRiderQuery($rider)->setMaxResults(20)->getQuery()->getResult(),
            'remittances' => $remittances->createForRiderQuery($rider)->setMaxResults(20)->getQuery()->getResult(),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
