<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\User;
use App\Form\AdminRefundFormType;
use App\Repository\AuditLogRepository;
use App\Repository\PaymentRepository;
use App\Service\PaymentTransitionService;
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

/** Payments for admins: filtering by method, status and paid date, and recording refunds. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/payment')]
final class AdminPaymentController extends AbstractController
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route(name: 'app_admin_payment', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderList($request);
    }

    /** Refunds the given amount, or everything not yet refunded when no amount is entered. */
    #[Route('/{id}/refund', name: 'app_admin_payment_refund', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refund(Request $request, Payment $payment, #[CurrentUser] User $admin, EntityManagerInterface $entityManager, PaymentTransitionService $transitions, AuditLogRepository $audit): Response
    {
        $form = $this->refundForm($payment)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderList($request, $payment, $form);
        }
        $requested = trim((string) $form->get('amount')->getData());
        $reason = trim((string) $form->get('reason')->getData());
        $reason = '' === $reason ? null : $reason;

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $transitions, $audit, $payment, $requested, $reason, $admin): void {
                $locked = $entityManager->find(Payment::class, $payment->getId(), LockMode::PESSIMISTIC_WRITE) ?? throw new \DomainException('Payment not found.');
                $from = $locked->getStatus();
                $amount = '' === $requested ? bcsub($locked->getAmount(), $locked->getRefundedAmount(), 2) : bcround($requested, 2);
                $to = $transitions->refund($locked, $amount, Payment::ACTOR_ADMIN, $reason, new \DateTimeImmutable());
                $audit->record($admin, 'payment.refunded', 'payment', (string) $locked->getId(), [
                    'status' => ['from' => $from, 'to' => $to],
                    'refund_amount' => $amount,
                    'refunded_amount' => $locked->getRefundedAmount(),
                    'reason' => $reason,
                ]);
                $entityManager->flush();
            });
            $this->addFlash('success', 'Refund recorded.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_payment', [], Response::HTTP_SEE_OTHER);
    }

    private function renderList(Request $request, ?Payment $failed = null, ?FormInterface $failedForm = null): Response
    {
        $method = $request->query->getString('method');
        $method = in_array($method, Payment::METHODS, true) ? $method : null;
        $status = $request->query->getString('status');
        $status = in_array($status, Payment::STATUSES, true) ? $status : null;
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('from')) ?: null;
        $to = (\DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('to')) ?: null)?->setTime(23, 59, 59);

        $payments = $this->paginator->paginate($this->payments->createAdminListQuery($method, $status, $from, $to), $request->query->getInt('page', 1), 25);
        $forms = [];
        foreach ($payments as $payment) {
            if (in_array($payment->getStatus(), [Payment::STATUS_PAID, Payment::STATUS_PARTIALLY_REFUNDED], true)) {
                $forms[$payment->getId()] = ($payment === $failed ? $failedForm : $this->refundForm($payment))->createView();
            }
        }

        return $this->render('admin_payment/index.html.twig', [
            'payments' => $payments,
            'forms' => $forms,
            'methods' => Payment::METHODS,
            'statuses' => Payment::STATUSES,
            'method' => $method,
            'status' => $status,
            'from' => $from,
            'to' => $to,
        ], new Response(status: null !== $failedForm ? 422 : 200));
    }

    private function refundForm(Payment $payment): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('refund_'.$payment->getId(), AdminRefundFormType::class);
    }
}
