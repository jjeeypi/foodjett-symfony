<?php

namespace App\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use App\Entity\Voucher;
use App\Form\VoucherType;
use App\Repository\VoucherRepository;
use App\Security\Voter\ApprovedAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Vouchers the restaurant funds itself; they only apply to its own orders. Redeemed vouchers are kept. */
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
#[Route('/restaurant/vouchers')]
final class RestaurantVoucherController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VoucherRepository $vouchers,
    ) {
    }

    #[Route(name: 'app_restaurant_voucher_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('restaurant_voucher/index.html.twig', [
            'vouchers' => $this->vouchers->createForRestaurantQuery($this->restaurant($user))->getQuery()->getResult(),
        ]);
    }

    #[Route('/new', name: 'app_restaurant_voucher_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $voucher = (new Voucher())->setIsActive(true);
        $form = $this->createForm(VoucherType::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $this->normalize($voucher)->setScope(Voucher::SCOPE_RESTAURANT)->setRestaurant($this->restaurant($user))
                ->setCreatedAt($now)->setUpdatedAt($now);
            $this->entityManager->persist($voucher);
            $this->entityManager->flush();
            $this->addFlash('success', 'Voucher created.');

            return $this->redirectToRoute('app_restaurant_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('restaurant_voucher/new.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_restaurant_voucher_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $id, #[CurrentUser] User $user): Response
    {
        return $this->render('restaurant_voucher/show.html.twig', [
            'voucher' => $this->owned($id, $user),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_restaurant_voucher_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $voucher = $this->owned($id, $user);
        $form = $this->createForm(VoucherType::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->normalize($voucher)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', 'Voucher updated.');

            return $this->redirectToRoute('app_restaurant_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('restaurant_voucher/edit.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_restaurant_voucher_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $voucher = $this->owned($id, $user);
        if (!$this->isCsrfTokenValid('delete'.$voucher->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$voucher->getRedemptions()->isEmpty()) {
            $this->addFlash('error', 'A voucher that was used cannot be deleted; deactivate it instead.');
        } else {
            $this->entityManager->remove($voucher);
            $this->entityManager->flush();
            $this->addFlash('success', 'Voucher deleted.');
        }

        return $this->redirectToRoute('app_restaurant_voucher_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Rounds amounts to centavos; free delivery has no value. */
    private function normalize(Voucher $voucher): Voucher
    {
        $value = $voucher->getValue();

        return $voucher
            ->setValue(Voucher::TYPE_FREE_DELIVERY === $voucher->getType() || null === $value || '' === $value ? null : bcround($value, 2))
            ->setMinOrderAmount(bcround($voucher->getMinOrderAmount(), 2));
    }

    private function owned(string $id, User $user): Voucher
    {
        return $this->vouchers->findOneForRestaurant($id, $this->restaurant($user)) ?? throw $this->createNotFoundException('Voucher not found.');
    }

    private function restaurant(User $user): Restaurant
    {
        return $user->getRestaurant() ?? throw $this->createAccessDeniedException();
    }
}
