<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\SupportTicketStatus;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\OrderStatusHistoryRepository;
use App\Repository\RestaurantRepository;
use App\Repository\RiderCashRemittanceRepository;
use App\Repository\RiderRepository;
use App\Repository\SupportTicketRepository;
use App\Repository\VoucherRedemptionRepository;
use App\Repository\VoucherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderStatusHistoryRepository $statusHistory,
        private readonly RestaurantRepository $restaurants,
        private readonly RiderRepository $riders,
        private readonly CustomerRepository $customers,
        private readonly SupportTicketRepository $supportTickets,
        private readonly RiderCashRemittanceRepository $remittances,
        private readonly VoucherRepository $vouchers,
        private readonly VoucherRedemptionRepository $voucherRedemptions,
    ) {
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

        $todayOrders = $this->orders->countPlacedBetween($todayStart, $tomorrow);
        $todayRevenue = $this->deliveredRevenue($todayStart, $tomorrow);
        $inProgress = $this->orders->countWithStatuses($inProgressStatuses);
        $completedToday = $this->orders->countDeliveredBetween($todayStart, $tomorrow);
        $cancelledToday = $this->statusHistory->countOrdersEnteringStatusesBetween($cancelledStatuses, $todayStart, $tomorrow);

        $openRestaurants = $this->restaurants->countOpenAndActive();
        $pendingRestaurants = $this->restaurants->countWithApprovalStatus(ApprovalStatus::PENDING);
        $approvedRestaurants = $this->restaurants->countWithApprovalStatus(ApprovalStatus::APPROVED);

        $onlineRiders = $this->riders->countOnline();
        $pendingRiders = $this->riders->countWithApprovalStatus(ApprovalStatus::PENDING);
        $approvedRiders = $this->riders->countWithApprovalStatus(ApprovalStatus::APPROVED);

        $openSupportTickets = $this->supportTickets->countWithStatuses([SupportTicketStatus::OPEN, SupportTicketStatus::IN_PROGRESS]);
        $pendingRemittances = (float) $this->remittances->sumPending();
        $activeVouchers = $this->vouchers->countActiveAt($now);
        $voucherRedemptions = $this->voucherRedemptions->countBetween($monthStart, $nextMonth);

        $totalCustomers = $this->customers->count([]);
        $newCustomers = $this->customers->countCreatedBetween($weekStart, $now);
        $newRestaurants = $this->restaurants->countCreatedBetween($weekStart, $now);
        $newRiders = $this->riders->countCreatedBetween($weekStart, $now);

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
        return (float) $this->orders->sumDeliveredTotalBetween($start, $end);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
