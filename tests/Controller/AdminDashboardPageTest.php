<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Entity\Voucher;
use App\Entity\VoucherRedemption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The admin dashboard shows real counts and sums, grouped by area. */
class AdminDashboardPageTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';

    private KernelBrowser $client;
    private User $adminUser;
    private User $viewerUser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $entityManager = $this->entityManager();
        $then = new \DateTimeImmutable('-2 weeks');
        $this->adminUser = $this->user('Dashboard Admin', 'dashboard-admin@foodjett.test', '+639193000001', User::ACCOUNT_ADMIN, $then);
        $admin = (new Admin())->setUser($this->adminUser)->setCreatedAt($then)->setUpdatedAt($then);
        $this->viewerUser = $this->user('Dashboard Viewer', 'dashboard-viewer@foodjett.test', '+639193000002', User::ACCOUNT_CUSTOMER, $then);
        $viewer = (new Customer())->setUser($this->viewerUser)->setCreatedAt($then)->setUpdatedAt($then);
        foreach ([$this->adminUser, $admin, $this->viewerUser, $viewer] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    public function testDashboardShowsRealGroupedTotals(): void
    {
        $this->client->loginUser($this->adminUser, 'main');
        $before = $this->dashboard();
        $this->createMetricFixture();
        $after = $this->dashboard();

        $counts = [
            'operations-today_orders' => 3, 'operations-orders_in_progress' => 1, 'operations-completed_today' => 1, 'operations-cancelled_today' => 1,
            'platform_health-restaurants_open' => 1, 'platform_health-riders_online' => 1, 'platform_health-pending_approvals' => 2,
            'platform_health-pending_restaurants' => 1, 'platform_health-pending_riders' => 1, 'platform_health-open_support_tickets' => 1,
            'financial-active_vouchers' => 1, 'financial-voucher_redemptions' => 1,
            'growth-total_customers' => 1, 'growth-approved_restaurants' => 1, 'growth-approved_riders' => 1,
            'growth-new_signups_this_week-customers' => 1, 'growth-new_signups_this_week-restaurants' => 2,
            'growth-new_signups_this_week-riders' => 2, 'growth-new_signups_this_week-total' => 5,
        ];
        foreach ($counts as $id => $delta) {
            self::assertSame((int) $before[$id] + $delta, (int) $after[$id], $id);
        }
        foreach (['operations-today_revenue' => '150.00', 'financial-total_revenue' => '150.00', 'financial-pending_remittances_amount' => '40.00'] as $id => $delta) {
            self::assertSame($delta, bcsub($after[$id], $before[$id], 2), $id);
        }
        self::assertSame('month', $after['financial-period']);
    }

    public function testDashboardRefusesNonAdmins(): void
    {
        $this->client->loginUser($this->viewerUser, 'main');
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, string> the value of every stat, keyed by its element id */
    private function dashboard(): array
    {
        $this->entityManager()->clear();
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $values = [];
        $crawler->filter('dd[id]')->each(static function (Crawler $node) use (&$values): void {
            $values[(string) $node->attr('id')] = trim($node->text());
        });

        return $values;
    }

    private function createMetricFixture(): void
    {
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $customerUser = $this->user('Dashboard Customer', 'dashboard-customer@foodjett.test', '+639193000003', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Dashboard Address')->setAddressLine('Dashboard Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);

        $restaurantUser = $this->user('Dashboard Restaurant Owner', 'dashboard-restaurant@foodjett.test', '+639193000004', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = $this->restaurant($restaurantUser, 'Dashboard Restaurant', 'dashboard-restaurant', Restaurant::APPROVAL_APPROVED, Restaurant::OPERATING_OPEN, $now);
        $pendingRestaurantUser = $this->user('Pending Dashboard Owner', 'dashboard-pending-restaurant@foodjett.test', '+639193000005', User::ACCOUNT_RESTAURANT, $now);
        $pendingRestaurant = $this->restaurant($pendingRestaurantUser, 'Pending Dashboard Restaurant', 'pending-dashboard-restaurant', Restaurant::APPROVAL_PENDING, Restaurant::OPERATING_CLOSED, $now);

        $riderUser = $this->user('Dashboard Rider', 'dashboard-rider@foodjett.test', '+639193000006', User::ACCOUNT_RIDER, $now);
        $rider = $this->rider($riderUser, Rider::APPROVAL_APPROVED, Rider::AVAILABILITY_AVAILABLE, $now);
        $pendingRiderUser = $this->user('Pending Dashboard Rider', 'dashboard-pending-rider@foodjett.test', '+639193000007', User::ACCOUNT_RIDER, $now);
        $pendingRider = $this->rider($pendingRiderUser, Rider::APPROVAL_PENDING, Rider::AVAILABILITY_OFFLINE, $now);

        // Every "today" fixture uses the current instant so the test cannot cross midnight.
        $delivered = $this->order('DASHBOARD-DELIVERED', $customer, $address, $restaurant, Order::STATUS_DELIVERED, '150.00', $now)->setDeliveredAt($now);
        $preparing = $this->order('DASHBOARD-PREPARING', $customer, $address, $restaurant, Order::STATUS_PREPARING, '90.00', $now);
        $cancelled = $this->order('DASHBOARD-CANCELLED', $customer, $address, $restaurant, Order::STATUS_CANCELLED_BY_CUSTOMER, '75.00', $now);
        $cancelledHistory = (new OrderStatusHistory())->setOrder($cancelled)->setStatus(Order::STATUS_CANCELLED_BY_CUSTOMER)
            ->setChangedBy(Order::ACTOR_CUSTOMER)->setNote('Dashboard test cancellation')->setCreatedAt($now);

        $remittance = (new RiderCashRemittance())->setRider($rider)->setAmount('40.00')->setStatus(RiderCashRemittance::STATUS_PENDING)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $voucher = (new Voucher())->setCode('DASHBOARD-ACTIVE')->setScope(Voucher::SCOPE_PLATFORM)->setType(Voucher::TYPE_FIXED)
            ->setValue('10.00')->setIsActive(true)->setStartsAt($now->modify('-1 day'))->setEndsAt($now->modify('+1 day'))
            ->setCreatedAt($now)->setUpdatedAt($now);
        $redemption = (new VoucherRedemption())->setVoucher($voucher)->setOrder($delivered)->setCustomer($customer)
            ->setDiscountApplied('10.00')->setCreatedAt($now)->setUpdatedAt($now);
        $ticket = (new SupportTicket())->setUser($customerUser)->setSubject('Dashboard support ticket')->setMessage('Please help with this dashboard test.')
            ->setStatus(SupportTicket::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);

        foreach ([$customerUser, $customer, $address, $restaurantUser, $restaurant, $pendingRestaurantUser, $pendingRestaurant,
            $riderUser, $rider, $pendingRiderUser, $pendingRider, $delivered, $preparing, $cancelled, $cancelledHistory,
            $remittance, $voucher, $redemption, $ticket] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    private function restaurant(User $user, string $name, string $slug, string $approval, string $operating, \DateTimeImmutable $now): Restaurant
    {
        return (new Restaurant())->setUser($user)->setName($name)->setSlug($slug)->setAddress('Dashboard Restaurant Street')
            ->setLatitude('14.5995000')->setLongitude('120.9842000')->setApprovalStatus($approval)->setOperatingStatus($operating)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function rider(User $user, string $approval, string $availability, \DateTimeImmutable $now): Rider
    {
        return (new Rider())->setUser($user)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setPlateNumber('DASH-'.substr((string) $user->getPhone(), -3))
            ->setApprovalStatus($approval)->setAvailabilityStatus($availability)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function order(string $number, Customer $customer, CustomerAddress $address, Restaurant $restaurant, string $status, string $total, \DateTimeImmutable $placedAt): Order
    {
        return (new Order())->setOrderNumber($number)->setCustomer($customer)->setCustomerAddress($address)->setRestaurant($restaurant)
            ->setStatus($status)->setSubtotal($total)->setDeliveryFee('0.00')->setTotalAmount($total)
            ->setPaymentMethod(Payment::METHOD_COD)->setPlacedAt($placedAt)->setCreatedAt($placedAt)->setUpdatedAt($placedAt);
    }

    private function user(string $name, string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
