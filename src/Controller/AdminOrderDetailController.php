<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Rider;
use App\Entity\User;
use App\Form\AdminAssignRiderFormType;
use App\Form\AdminReasonFormType;
use App\Repository\AuditLogRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One order for admins: everything about it, cancelling it with a refund, and handing it
 * to a rider when the pool found nobody. Both actions are audited.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/order/{id}', requirements: ['id' => '\d+'])]
final class AdminOrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly PaymentTransitionService $payments,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository $audit,
    ) {
    }

    #[Route(name: 'app_admin_order_detail', methods: ['GET'])]
    public function index(Order $order): Response
    {
        return $this->renderDetail($order);
    }

    #[Route('/cancel', name: 'app_admin_order_cancel', methods: ['POST'])]
    public function cancel(Request $request, Order $order, #[CurrentUser] User $admin): Response
    {
        $form = $this->cancelForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, cancel: $form);
        }
        if (Order::isTerminalStatus($order->getStatus())) {
            $this->addFlash('error', 'This order is already finished.');

            return $this->redirectToRoute('app_admin_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }
        $reason = trim((string) $form->get('reason')->getData());

        try {
            $this->transitions->transition($order, Order::STATUS_CANCELLED_BY_ADMIN, Order::ACTOR_ADMIN, $reason,
                function (Order $locked, \DateTimeImmutable $now) use ($reason, $admin): void {
                    $from = $locked->getStatus();
                    $locked->setCancellationReason($reason)->setCancelledBy(Order::ACTOR_ADMIN);
                    if (null !== $locked->getPayment()) {
                        $this->payments->cancelOrRefund($locked->getPayment(), Payment::ACTOR_ADMIN, $reason, $now);
                    }
                    $this->audit->record($admin, 'order.cancelled', 'order', (string) $locked->getId(), [
                        'status' => ['from' => $from, 'to' => Order::STATUS_CANCELLED_BY_ADMIN],
                        'reason' => $reason,
                    ]);
                },
            );
            $this->addFlash('success', 'Order cancelled.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/assign', name: 'app_admin_order_assign', methods: ['POST'])]
    public function assign(Request $request, Order $order, #[CurrentUser] User $admin): Response
    {
        $form = $this->createForm(AdminAssignRiderFormType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($order, assign: $form);
        }
        /** @var Rider $rider */
        $rider = $form->get('rider')->getData();

        try {
            $this->transitions->acceptPoolOrder($order, $rider, adminOverride: true);
            $this->audit->record($admin, 'order.rider_assigned', 'order', (string) $order->getId(), [
                'rider_id' => $rider->getId(),
                'admin_assigned' => true,
            ]);
            $this->entityManager->flush();
            $this->addFlash('success', $rider->getUser()->getName().' is now delivering this order.');
        } catch (HttpException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\DomainException) {
            $this->addFlash('error', 'This order was just taken by another rider.');
        }

        return $this->redirectToRoute('app_admin_order_detail', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    private function renderDetail(Order $order, ?FormInterface $cancel = null, ?FormInterface $assign = null): Response
    {
        $history = $order->getStatusHistory()->toArray();
        usort($history, static fn ($a, $b): int => ($a->getCreatedAt() <=> $b->getCreatedAt()) ?: ((int) $a->getId() <=> (int) $b->getId()));
        $canAssign = Order::STATUS_FINDING_RIDER === $order->getStatus() && null === $order->getRider() && null !== $order->getRiderPoolOffer();

        return $this->render('admin_order_detail/index.html.twig', [
            'order' => $order,
            'history' => $history,
            'cancel_form' => Order::isTerminalStatus($order->getStatus()) ? null : ($cancel ?? $this->cancelForm())->createView(),
            'assign_form' => $canAssign ? ($assign ?? $this->createForm(AdminAssignRiderFormType::class))->createView() : null,
        ], new Response(status: null !== $cancel || null !== $assign ? 422 : 200));
    }

    private function cancelForm(): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('cancel', AdminReasonFormType::class, null, [
            'reason_label' => 'Why is the order cancelled? The customer and restaurant see this.',
        ]);
    }
}
