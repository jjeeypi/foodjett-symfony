<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/customers', name: 'api_admin_customers_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCustomerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Customer::class)->createQueryBuilder('customer')
            ->innerJoin('customer.user', 'account')->addSelect('account')
            ->orderBy('customer.createdAt', 'DESC');
        if ('' !== ($search = trim((string) $request->query->get('search', '')))) {
            $query->andWhere('LOWER(account.name) LIKE :search OR LOWER(COALESCE(account.email, \'\')) LIKE :search OR COALESCE(account.phone, \'\') LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $this->json($this->paginator->paginate($query, $request, fn (Customer $customer): array => $this->normalize($customer)));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], priority: -10)]
    public function show(Customer $customer): JsonResponse
    {
        $summary = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->select('COUNT(orders.id) AS order_count, COALESCE(SUM(orders.totalAmount), 0) AS lifetime_spend')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getSingleResult();
        /** @var list<Order> $recent */
        $recent = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->innerJoin('orders.restaurant', 'restaurant')->addSelect('restaurant')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('orders.placedAt', 'DESC')->setMaxResults(10)->getQuery()->getResult();

        return $this->json(['customer' => $this->normalize($customer) + [
            'order_summary' => [
                'order_count' => (int) $summary['order_count'],
                'lifetime_spend' => number_format((float) $summary['lifetime_spend'], 2, '.', ''),
            ],
            'recent_orders' => array_map(static fn (Order $order): array => [
                'id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'restaurant_name' => $order->getRestaurant()->getName(),
                'status' => $order->getStatus()->value,
                'total_amount' => $order->getTotalAmount(),
                'placed_at' => $order->getPlacedAt()->format(\DateTimeInterface::ATOM),
            ], $recent),
        ]]);
    }

    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(Customer $customer): JsonResponse
    {
        return $this->setStatus($customer, UserStatus::SUSPENDED);
    }

    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(Customer $customer): JsonResponse
    {
        return $this->setStatus($customer, UserStatus::ACTIVE);
    }

    private function setStatus(Customer $customer, UserStatus $status): JsonResponse
    {
        $before = $customer->getUser()->getStatus();
        $customer->getUser()->setStatus($status)->setUpdatedAt(new \DateTimeImmutable());
        $admin = $this->getUser();
        if (!$admin instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $this->audit->record($admin, 'customer.'.(UserStatus::ACTIVE === $status ? 'reactivated' : 'suspended'), 'customer', (string) $customer->getId(), [
            'user_status' => ['from' => $before->value, 'to' => $status->value],
        ]);
        $this->entityManager->flush();

        return $this->json(['customer' => $this->normalize($customer)]);
    }

    /** @return array<string, mixed> */
    private function normalize(Customer $customer): array
    {
        return [
            'id' => $customer->getId(),
            'name' => $customer->getUser()->getName(),
            'email' => $customer->getUser()->getEmail(),
            'phone' => $customer->getUser()->getPhone(),
            'avatar_path' => $customer->getUser()->getAvatarPath(),
            'status' => $customer->getUser()->getStatus()->value,
            'created_at' => $customer->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
