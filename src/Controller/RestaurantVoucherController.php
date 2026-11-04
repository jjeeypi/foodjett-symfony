<?php

namespace App\Controller;

use App\Entity\Voucher;
use App\Form\VoucherType;
use App\Repository\VoucherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/restaurant/voucher')]
final class RestaurantVoucherController extends AbstractController
{
    #[Route(name: 'app_restaurant_voucher_index', methods: ['GET'])]
    public function index(VoucherRepository $voucherRepository): Response
    {
        return $this->render('restaurant_voucher/index.html.twig', [
            'vouchers' => $voucherRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_restaurant_voucher_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $voucher = new Voucher();
        $form = $this->createForm(VoucherType::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($voucher);
            $entityManager->flush();

            return $this->redirectToRoute('app_restaurant_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('restaurant_voucher/new.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_restaurant_voucher_show', methods: ['GET'])]
    public function show(Voucher $voucher): Response
    {
        return $this->render('restaurant_voucher/show.html.twig', [
            'voucher' => $voucher,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_restaurant_voucher_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Voucher $voucher, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(VoucherType::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_restaurant_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('restaurant_voucher/edit.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_restaurant_voucher_delete', methods: ['POST'])]
    public function delete(Request $request, Voucher $voucher, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$voucher->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($voucher);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_restaurant_voucher_index', [], Response::HTTP_SEE_OTHER);
    }
}
