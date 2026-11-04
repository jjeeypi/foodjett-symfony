<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\User;
use App\Form\CustomerAddressType;
use App\Repository\CustomerAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer's delivery addresses. The first address becomes the default, choosing a new
 * default clears the old one, and addresses used by past orders cannot be deleted.
 */
#[IsGranted('ROLE_CUSTOMER')]
#[Route('/account/addresses')]
final class CustomerAddressController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerAddressRepository $addresses,
    ) {
    }

    #[Route(name: 'app_customer_address_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('customer_address/index.html.twig', [
            'customer_addresses' => $this->addresses->findForCustomer($this->customer($user)),
        ]);
    }

    #[Route('/new', name: 'app_customer_address_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $customer = $this->customer($user);
        $customerAddress = new CustomerAddress();
        $form = $this->createForm(CustomerAddressType::class, $customerAddress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTimeImmutable();
            $first = [] === $this->addresses->findForCustomer($customer);
            $customerAddress->setCustomer($customer)->setCreatedAt($now)->setUpdatedAt($now)
                ->setIsDefault($first || $customerAddress->isDefault());
            $this->save($customerAddress, $customer);
            $this->addFlash('success', 'Address saved.');

            return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('customer_address/new.html.twig', [
            'customer_address' => $customerAddress,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_customer_address_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $customer = $this->customer($user);
        $customerAddress = $this->owned($id, $customer);
        $form = $this->createForm(CustomerAddressType::class, $customerAddress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $customerAddress->setUpdatedAt(new \DateTimeImmutable());
            $this->save($customerAddress, $customer);
            $this->addFlash('success', 'Address updated.');

            return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('customer_address/edit.html.twig', [
            'customer_address' => $customerAddress,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_customer_address_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $customer = $this->customer($user);
        $customerAddress = $this->owned($id, $customer);
        if (!$this->isCsrfTokenValid('delete'.$customerAddress->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        if (!$customerAddress->getOrders()->isEmpty()) {
            $this->addFlash('error', 'This address is part of your order history and cannot be deleted.');

            return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
        }

        $wasDefault = $customerAddress->isDefault();
        $this->entityManager->wrapInTransaction(function () use ($customerAddress, $wasDefault, $customer): void {
            $this->entityManager->remove($customerAddress);
            $this->entityManager->flush();
            if ($wasDefault) {
                $replacement = $this->addresses->findLatestForCustomer($customer);
                if (null !== $replacement) {
                    // reload: the loaded copy may predate an earlier bulk update of the defaults
                    $this->entityManager->refresh($replacement);
                    $replacement->setIsDefault(true)->setUpdatedAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                }
            }
        });
        $this->addFlash('success', 'Address deleted.');

        return $this->redirectToRoute('app_customer_address_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Saves the address; a new default clears the customer's other defaults first. */
    private function save(CustomerAddress $address, Customer $customer): void
    {
        $this->entityManager->wrapInTransaction(function () use ($address, $customer): void {
            if ($address->isDefault()) {
                $this->addresses->clearDefaultForCustomer($customer->getId(), $address);
            }
            $this->entityManager->persist($address);
            $this->entityManager->flush();
        });
    }

    private function owned(string $id, Customer $customer): CustomerAddress
    {
        return $this->addresses->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException('Address not found.');
    }

    private function customer(User $user): Customer
    {
        return $user->getCustomer() ?? throw $this->createAccessDeniedException();
    }
}
