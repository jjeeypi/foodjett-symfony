<?php

namespace App\Controller;

use App\Entity\Voucher;
use App\Form\Voucher1Type;
use App\Repository\VoucherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/voucher')]
final class AdminVoucherController extends AbstractController
{
    #[Route(name: 'app_admin_voucher_index', methods: ['GET'])]
    public function index(VoucherRepository $voucherRepository): Response
    {
        return $this->render('admin_voucher/index.html.twig', [
            'vouchers' => $voucherRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_voucher_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $voucher = new Voucher();
        $form = $this->createForm(Voucher1Type::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($voucher);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_voucher/new.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_voucher_show', methods: ['GET'])]
    public function show(Voucher $voucher): Response
    {
        return $this->render('admin_voucher/show.html.twig', [
            'voucher' => $voucher,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_voucher_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Voucher $voucher, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(Voucher1Type::class, $voucher);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_voucher_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_voucher/edit.html.twig', [
            'voucher' => $voucher,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_voucher_delete', methods: ['POST'])]
    public function delete(Request $request, Voucher $voucher, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$voucher->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($voucher);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_voucher_index', [], Response::HTTP_SEE_OTHER);
    }
}
