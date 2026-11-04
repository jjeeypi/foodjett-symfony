<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Customers for admins: search, order summary, and suspending or reactivating the account. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/customer')]
final class AdminCustomerController extends AbstractController
{
    #[Route(name: 'app_admin_customer_index', methods: ['GET'])]
    public function index(Request $request, CustomerRepository $customerRepository, PaginatorInterface $paginator): Response
    {
        $search = trim($request->query->getString('search'));

        return $this->render('admin_customer/index.html.twig', [
            'customers' => $paginator->paginate($customerRepository->createAdminListQuery($search), $request->query->getInt('page', 1), 20),
            'search' => $search,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_customer_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Customer $customer, OrderRepository $orders): Response
    {
        $summary = $orders->summarizeForCustomer($customer);

        return $this->render('admin_customer/show.html.twig', [
            'customer' => $customer,
            'order_count' => (int) $summary['order_count'],
            'lifetime_spend' => bcround((string) $summary['lifetime_spend'], 2),
            'recent_orders' => $orders->findRecentForCustomer($customer),
        ]);
    }

    /** Suspends or reactivates the customer's account; a suspended customer is signed out. */
    #[Route('/{id}/account/{status}', name: 'app_admin_customer_account', methods: ['POST'], requirements: ['id' => '\d+', 'status' => 'suspended|active'])]
    public function account(Request $request, Customer $customer, string $status, #[CurrentUser] User $admin, EntityManagerInterface $entityManager, AuditLogRepository $audit): Response
    {
        if (!$this->isCsrfTokenValid('account'.$customer->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }
        $user = $customer->getUser();
        $before = $user->getStatus();
        $user->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $audit->record($admin, 'customer.'.(User::STATUS_ACTIVE === $status ? 'reactivated' : 'suspended'), 'customer', (string) $customer->getId(), [
            'user_status' => ['from' => $before, 'to' => $status],
        ]);
        $entityManager->flush();
        $this->addFlash('success', User::STATUS_ACTIVE === $status ? 'Account reactivated.' : 'Account suspended.');

        return $this->redirectToRoute('app_admin_customer_show', ['id' => $customer->getId()], Response::HTTP_SEE_OTHER);
    }
}
