<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\SupportTicket;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable();
        $todayStart = $now->setTime(0, 0);
        $tomorrow = $todayStart->modify('+1 day');
        $weekStart = $todayStart->modify('monday this week');
        $monthStart = $todayStart->modify('first day of this month');
        $nextMonth = $monthStart->modify('first day of next month');

        $inProgressStatuses = [
            Order::STATUS_PLACED,
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
            Order::STATUS_READY,
            Order::STATUS_FINDING_RIDER,
            Order::STATUS_RIDER_ASSIGNED,
            Order::STATUS_AT_RESTAURANT,
            Order::STATUS_PICKED_UP,
            Order::STATUS_ON_THE_WAY,
            Order::STATUS_ARRIVED,
        ];
        $cancelledStatuses = [
            Order::STATUS_REJECTED_BY_RESTAURANT,
            Order::STATUS_CANCELLED_BY_CUSTOMER,
            Order::STATUS_CANCELLED_BY_RESTAURANT,
            Order::STATUS_CANCELLED_NO_RIDER,
            Order::STATUS_CANCELLED_BY_ADMIN,
            Order::STATUS_FAILED_DELIVERY,
        ];

        $todayOrders = $this->orders->countPlacedBetween($todayStart, $tomorrow);
        $todayRevenue = $this->deliveredRevenue($todayStart, $tomorrow);
        $inProgress = $this->orders->countWithStatuses($inProgressStatuses);
        $completedToday = $this->orders->countDeliveredBetween($todayStart, $tomorrow);
        $cancelledToday = $this->statusHistory->countOrdersEnteringStatusesBetween($cancelledStatuses, $todayStart, $tomorrow);

        $openRestaurants = $this->restaurants->countOpenAndActive();
        $pendingRestaurants = $this->restaurants->countWithApprovalStatus(Restaurant::APPROVAL_PENDING);
        $approvedRestaurants = $this->restaurants->countWithApprovalStatus(Restaurant::APPROVAL_APPROVED);

        $onlineRiders = $this->riders->countOnline();
        $pendingRiders = $this->riders->countWithApprovalStatus(Rider::APPROVAL_PENDING);
        $approvedRiders = $this->riders->countWithApprovalStatus(Rider::APPROVAL_APPROVED);

        $openSupportTickets = $this->supportTickets->countWithStatuses([SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS]);
        $pendingRemittances = $this->remittances->sumPending();
        $activeVouchers = $this->vouchers->countActiveAt($now);
        $voucherRedemptions = $this->voucherRedemptions->countBetween($monthStart, $nextMonth);

        $totalCustomers = $this->customers->count([]);
        $newCustomers = $this->customers->countCreatedBetween($weekStart, $now);
        $newRestaurants = $this->restaurants->countCreatedBetween($weekStart, $now);
        $newRiders = $this->riders->countCreatedBetween($weekStart, $now);

        return $this->render('admin_dashboard/index.html.twig', ['stats' => [
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
        ]]);
    }

    private function deliveredRevenue(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        return $this->orders->sumDeliveredTotalBetween($start, $end);
    }

    private function money(string $amount): string
    {
        return bcround($amount, 2);
    }
}
