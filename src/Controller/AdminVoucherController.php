<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Voucher;
use App\Form\VoucherType;
use App\Repository\AuditLogRepository;
use App\Repository\VoucherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Platform-wide vouchers, usable at any restaurant. Restaurants manage their own on /restaurant/vouchers. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/voucher')]
final class AdminVoucherController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VoucherRepository $vouchers,
        private readonly AuditLogRepository $audit,
    ) {
    }

    #[Route(name: 'app_admin_voucher_index', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        return $this->render('admin_voucher/index.html.twig', [
            'vouchers' => $paginator->paginate($this->vouchers->createPlatformListQuery(), $request->query->getInt('page', 1), 25),
        ]);
    }

    #[Route('/new', name: 'app_admin_voucher_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $admin): Response
    {
        $voucher = (new Voucher())->setIsActive(true)->setScope(Voucher::SCOPE_PLATFORM);
        $form = $this->createForm(VoucherType::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $this->normalize($voucher)->setScope(Voucher::SCOPE_PLATFORM)->setRestaurant(null)->setCreatedAt($now)->setUpdatedAt($now);
            $this->entityManager->persist($voucher);
            $this->entityManager->flush();
            $this->audit->record($admin, 'voucher.created', 'voucher', (string) $voucher->getId(), $this->snapshot($voucher));
            $this->entityManager->flush();
            $this->addFlash('success', 'Voucher created.');

            return $this->redirectToRoute('app_admin_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_voucher/new.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_voucher_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $id): Response
    {
        return $this->render('admin_voucher/show.html.twig', [
            'voucher' => $this->platformVoucher($id),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_voucher_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, string $id, #[CurrentUser] User $admin): Response
    {
        $voucher = $this->platformVoucher($id);
        $before = $this->snapshot($voucher);
        $form = $this->createForm(VoucherType::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->normalize($voucher)->setUpdatedAt(new \DateTimeImmutable());
            $this->audit->record($admin, 'voucher.updated', 'voucher', (string) $voucher->getId(), ['before' => $before, 'after' => $this->snapshot($voucher)]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Voucher updated.');

            return $this->redirectToRoute('app_admin_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_voucher/edit.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    /** A voucher that was used stays for the records; deactivate it instead. */
    #[Route('/{id}', name: 'app_admin_voucher_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, string $id, #[CurrentUser] User $admin): Response
    {
        $voucher = $this->platformVoucher($id);
        if (!$this->isCsrfTokenValid('delete'.$voucher->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$voucher->getRedemptions()->isEmpty()) {
            $this->addFlash('error', 'A voucher that was used cannot be deleted; deactivate it instead.');
        } else {
            $this->audit->record($admin, 'voucher.deleted', 'voucher', (string) $voucher->getId(), $this->snapshot($voucher));
            $this->entityManager->remove($voucher);
            $this->entityManager->flush();
            $this->addFlash('success', 'Voucher deleted.');
        }

        return $this->redirectToRoute('app_admin_voucher_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Rounds amounts to centavos; free delivery has no value. */
    private function normalize(Voucher $voucher): Voucher
    {
        $value = $voucher->getValue();

        return $voucher
            ->setValue(Voucher::TYPE_FREE_DELIVERY === $voucher->getType() || null === $value || '' === $value ? null : bcround($value, 2))
            ->setMinOrderAmount(bcround($voucher->getMinOrderAmount(), 2));
    }

    /** @return array<string, mixed> */
    private function snapshot(Voucher $voucher): array
    {
        return [
            'code' => $voucher->getCode(), 'type' => $voucher->getType(), 'value' => $voucher->getValue(),
            'min_order_amount' => $voucher->getMinOrderAmount(), 'usage_limit_total' => $voucher->getUsageLimitTotal(),
            'usage_limit_per_customer' => $voucher->getUsageLimitPerCustomer(),
            'starts_at' => $voucher->getStartsAt()?->format(\DateTimeInterface::ATOM), 'ends_at' => $voucher->getEndsAt()?->format(\DateTimeInterface::ATOM),
            'is_active' => $voucher->isActive(),
        ];
    }

    private function platformVoucher(string $id): Voucher
    {
        $voucher = $this->vouchers->find($id);

        return $voucher instanceof Voucher && Voucher::SCOPE_PLATFORM === $voucher->getScope() ? $voucher : throw $this->createNotFoundException('Voucher not found.');
    }
}
