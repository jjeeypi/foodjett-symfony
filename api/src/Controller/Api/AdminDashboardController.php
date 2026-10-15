<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\SupportTicket;
use App\Entity\Voucher;
use App\Entity\VoucherRedemption;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\RemittanceStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\SupportTicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $todayStart = $now->setTime(0, 0);
        $tomorrow = $todayStart->modify('+1 day');
        $weekStart = $todayStart->modify('monday this week');
        $monthStart = $todayStart->modify('first day of this month');
        $nextMonth = $monthStart->modify('first day of next month');

        $inProgressStatuses = [
            OrderStatus::PLACED,
            OrderStatus::ACCEPTED,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::FINDING_RIDER,
            OrderStatus::RIDER_ASSIGNED,
            OrderStatus::AT_RESTAURANT,
            OrderStatus::PICKED_UP,
            OrderStatus::ON_THE_WAY,
            OrderStatus::ARRIVED,
        ];
        $cancelledStatuses = [
            OrderStatus::REJECTED_BY_RESTAURANT->value,
            OrderStatus::CANCELLED_BY_CUSTOMER->value,
            OrderStatus::CANCELLED_BY_RESTAURANT->value,
            OrderStatus::CANCELLED_NO_RIDER->value,
            OrderStatus::CANCELLED_BY_ADMIN->value,
            OrderStatus::FAILED_DELIVERY->value,
        ];

        $orders = $this->entityManager->getRepository(Order::class);
        $todayOrders = (int) $orders->createQueryBuilder('orders')
            ->select('COUNT(orders.id)')
            ->andWhere('orders.placedAt >= :start AND orders.placedAt < :end')
            ->setParameter('start', $todayStart)->setParameter('end', $tomorrow)
            ->getQuery()->getSingleScalarResult();
        $todayRevenue = $this->deliveredRevenue($todayStart, $tomorrow);
        $inProgress = (int) $orders->createQueryBuilder('orders')
            ->select('COUNT(orders.id)')
            ->andWhere('orders.status IN (:statuses)')
            ->setParameter('statuses', array_map(static fn (OrderStatus $status): string => $status->value, $inProgressStatuses))
            ->getQuery()->getSingleScalarResult();
        $completedToday = (int) $orders->createQueryBuilder('orders')
            ->select('COUNT(orders.id)')
            ->andWhere('orders.status = :status')->setParameter('status', OrderStatus::DELIVERED->value)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt < :end')
            ->setParameter('start', $todayStart)->setParameter('end', $tomorrow)
            ->getQuery()->getSingleScalarResult();
        $cancelledToday = (int) $this->entityManager->getRepository(OrderStatusHistory::class)->createQueryBuilder('history')
            ->select('COUNT(DISTINCT IDENTITY(history.order))')
            ->andWhere('history.status IN (:statuses)')->setParameter('statuses', $cancelledStatuses)
            ->andWhere('history.createdAt >= :start AND history.createdAt < :end')
            ->setParameter('start', $todayStart)->setParameter('end', $tomorrow)
            ->getQuery()->getSingleScalarResult();

        $restaurants = $this->entityManager->getRepository(Restaurant::class);
        $openRestaurants = (int) $restaurants->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->innerJoin('restaurant.user', 'account')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->andWhere('restaurant.operatingStatus = :open')->setParameter('open', RestaurantOperatingStatus::OPEN->value)
            ->andWhere('account.status = :active')->setParameter('active', 'active')
            ->getQuery()->getSingleScalarResult();
        $pendingRestaurants = (int) $restaurants->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.approvalStatus = :pending')->setParameter('pending', ApprovalStatus::PENDING->value)
            ->getQuery()->getSingleScalarResult();
        $approvedRestaurants = (int) $restaurants->createQueryBuilder('restaurant')
            ->select('COUNT(restaurant.id)')
            ->andWhere('restaurant.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->getQuery()->getSingleScalarResult();

        $riders = $this->entityManager->getRepository(Rider::class);
        $onlineRiders = (int) $riders->createQueryBuilder('rider')
            ->select('COUNT(rider.id)')
            ->innerJoin('rider.user', 'account')
            ->andWhere('rider.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->andWhere('rider.availabilityStatus IN (:online)')->setParameter('online', [RiderAvailabilityStatus::AVAILABLE->value, RiderAvailabilityStatus::BUSY->value])
            ->andWhere('account.status = :active')->setParameter('active', 'active')
            ->getQuery()->getSingleScalarResult();
        $pendingRiders = (int) $riders->createQueryBuilder('rider')
            ->select('COUNT(rider.id)')
            ->andWhere('rider.approvalStatus = :pending')->setParameter('pending', ApprovalStatus::PENDING->value)
            ->getQuery()->getSingleScalarResult();
        $approvedRiders = (int) $riders->createQueryBuilder('rider')
            ->select('COUNT(rider.id)')
            ->andWhere('rider.approvalStatus = :approved')->setParameter('approved', ApprovalStatus::APPROVED->value)
            ->getQuery()->getSingleScalarResult();

        $openSupportTickets = (int) $this->entityManager->getRepository(SupportTicket::class)->createQueryBuilder('ticket')
            ->select('COUNT(ticket.id)')
            ->andWhere('ticket.status IN (:statuses)')
            ->setParameter('statuses', [SupportTicketStatus::OPEN->value, SupportTicketStatus::IN_PROGRESS->value])
            ->getQuery()->getSingleScalarResult();
        $pendingRemittances = (float) $this->entityManager->getRepository(RiderCashRemittance::class)->createQueryBuilder('remittance')
            ->select('COALESCE(SUM(remittance.amount), 0)')
            ->andWhere('remittance.status = :status')->setParameter('status', RemittanceStatus::PENDING->value)
            ->getQuery()->getSingleScalarResult();
        $activeVouchers = (int) $this->entityManager->getRepository(Voucher::class)->createQueryBuilder('voucher')
            ->select('COUNT(voucher.id)')
            ->andWhere('voucher.isActive = :active')->setParameter('active', true)
            ->andWhere('(voucher.startsAt IS NULL OR voucher.startsAt <= :now)')
            ->andWhere('(voucher.endsAt IS NULL OR voucher.endsAt >= :now)')
            ->setParameter('now', $now)
            ->getQuery()->getSingleScalarResult();
        $voucherRedemptions = (int) $this->entityManager->getRepository(VoucherRedemption::class)->createQueryBuilder('redemption')
            ->select('COUNT(redemption.id)')
            ->andWhere('redemption.createdAt >= :start AND redemption.createdAt < :end')
            ->setParameter('start', $monthStart)->setParameter('end', $nextMonth)
            ->getQuery()->getSingleScalarResult();

        $customers = $this->entityManager->getRepository(Customer::class);
        $totalCustomers = (int) $customers->createQueryBuilder('customer')->select('COUNT(customer.id)')->getQuery()->getSingleScalarResult();
        $newCustomers = $this->countCreatedSince(Customer::class, 'customer', $weekStart, $now);
        $newRestaurants = $this->countCreatedSince(Restaurant::class, 'restaurant', $weekStart, $now);
        $newRiders = $this->countCreatedSince(Rider::class, 'rider', $weekStart, $now);

        return $this->json([
            'generated_at' => $now->format(\DateTimeInterface::ATOM),
            'operations' => [
                'today_orders' => $todayOrders,
                'today_revenue' => $this->money($todayRevenue),
                'orders_in_progress' => $inProgress,
                'completed_today' => $completedToday,
                'cancelled_today' => $cancelledToday,
            ],
            'platform_health' => [
                'restaurants_open' => $openRestaurants,
                'riders_online' => $onlineRiders,
                'pending_approvals' => $pendingRestaurants + $pendingRiders,
                'pending_restaurants' => $pendingRestaurants,
                'pending_riders' => $pendingRiders,
                'open_support_tickets' => $openSupportTickets,
            ],
            'financial' => [
                'period' => 'month',
                'period_start' => $monthStart->format('Y-m-d'),
                'total_revenue' => $this->money($this->deliveredRevenue($monthStart, $nextMonth)),
                'pending_remittances_amount' => $this->money($pendingRemittances),
                'active_vouchers' => $activeVouchers,
                'voucher_redemptions' => $voucherRedemptions,
            ],
            'growth' => [
                'total_customers' => $totalCustomers,
                'approved_restaurants' => $approvedRestaurants,
                'approved_riders' => $approvedRiders,
                'new_signups_this_week' => [
                    'total' => $newCustomers + $newRestaurants + $newRiders,
                    'customers' => $newCustomers,
                    'restaurants' => $newRestaurants,
                    'riders' => $newRiders,
                ],
            ],
        ]);
    }

    private function deliveredRevenue(\DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        return (float) $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->select('COALESCE(SUM(orders.totalAmount), 0)')
            ->andWhere('orders.status = :status')->setParameter('status', OrderStatus::DELIVERED->value)
            ->andWhere('orders.deliveredAt >= :start AND orders.deliveredAt < :end')
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    /** @param class-string $entityClass */
    private function countCreatedSince(string $entityClass, string $alias, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->entityManager->getRepository($entityClass)->createQueryBuilder($alias)
            ->select(sprintf('COUNT(%s.id)', $alias))
            ->andWhere(sprintf('%s.createdAt >= :start AND %s.createdAt <= :end', $alias, $alias))
            ->setParameter('start', $start)->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
