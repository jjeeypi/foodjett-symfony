<?php

namespace App\Controller;

use App\Entity\CustomerAddress;
use App\Form\CustomerAddressType;
use App\Repository\CustomerAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/customer/address')]
final class CustomerAddressController extends AbstractController
{
    #[Route(name: 'app_customer_address_index', methods: ['GET'])]
    public function index(CustomerAddressRepository $customerAddressRepository): Response
    {
        return $this->render('customer_address/index.html.twig', [
            'customer_addresses' => $customerAddressRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_customer_address_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $customerAddress = new CustomerAddress();
        $form = $this->createForm(CustomerAddressType::class, $customerAddress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($customerAddress);
            $entityManager->flush();

            return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('customer_address/new.html.twig', [
            'customer_address' => $customerAddress,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_customer_address_show', methods: ['GET'])]
    public function show(CustomerAddress $customerAddress): Response
    {
        return $this->render('customer_address/show.html.twig', [
            'customer_address' => $customerAddress,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_customer_address_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CustomerAddress $customerAddress, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CustomerAddressType::class, $customerAddress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('customer_address/edit.html.twig', [
            'customer_address' => $customerAddress,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_customer_address_delete', methods: ['POST'])]
    public function delete(Request $request, CustomerAddress $customerAddress, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$customerAddress->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($customerAddress);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
    }
}
