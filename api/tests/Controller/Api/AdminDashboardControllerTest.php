<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Entity\Voucher;
use App\Entity\VoucherRedemption;
use App\Enum\ApprovalStatus;
use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RemittanceStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\SupportTicketStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use App\Enum\VoucherScope;
use App\Enum\VoucherType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminDashboardControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'dashboard-admin@foodjett.test';
    private const VIEWER_EMAIL = 'dashboard-viewer@foodjett.test';
    private const CUSTOMER_EMAIL = 'dashboard-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'dashboard-restaurant@foodjett.test';
    private const PENDING_RESTAURANT_EMAIL = 'dashboard-pending-restaurant@foodjett.test';
    private const RIDER_EMAIL = 'dashboard-rider@foodjett.test';
    private const PENDING_RIDER_EMAIL = 'dashboard-pending-rider@foodjett.test';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        $this->createAuthenticationFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testDashboardReturnsRealGroupedAggregateDeltas(): void
    {
        $token = $this->login(self::ADMIN_EMAIL);
        $before = $this->dashboard($token);

        $this->createMetricFixture();
        $after = $this->dashboard($token);

        self::assertSame(($before['operations']['today_orders'] ?? 0) + 3, $after['operations']['today_orders']);
        self::assertMoneyDelta('150.00', $before['operations']['today_revenue'], $after['operations']['today_revenue']);
        self::assertSame(($before['operations']['orders_in_progress'] ?? 0) + 1, $after['operations']['orders_in_progress']);
        self::assertSame(($before['operations']['completed_today'] ?? 0) + 1, $after['operations']['completed_today']);
        self::assertSame(($before['operations']['cancelled_today'] ?? 0) + 1, $after['operations']['cancelled_today']);

        self::assertSame(($before['platform_health']['restaurants_open'] ?? 0) + 1, $after['platform_health']['restaurants_open']);
        self::assertSame(($before['platform_health']['riders_online'] ?? 0) + 1, $after['platform_health']['riders_online']);
        self::assertSame(($before['platform_health']['pending_approvals'] ?? 0) + 2, $after['platform_health']['pending_approvals']);
        self::assertSame(($before['platform_health']['pending_restaurants'] ?? 0) + 1, $after['platform_health']['pending_restaurants']);
        self::assertSame(($before['platform_health']['pending_riders'] ?? 0) + 1, $after['platform_health']['pending_riders']);
        self::assertSame(($before['platform_health']['open_support_tickets'] ?? 0) + 1, $after['platform_health']['open_support_tickets']);

        self::assertSame('month', $after['financial']['period']);
        self::assertMoneyDelta('150.00', $before['financial']['total_revenue'], $after['financial']['total_revenue']);
        self::assertMoneyDelta('40.00', $before['financial']['pending_remittances_amount'], $after['financial']['pending_remittances_amount']);
        self::assertSame(($before['financial']['active_vouchers'] ?? 0) + 1, $after['financial']['active_vouchers']);
        self::assertSame(($before['financial']['voucher_redemptions'] ?? 0) + 1, $after['financial']['voucher_redemptions']);

        self::assertSame(($before['growth']['total_customers'] ?? 0) + 1, $after['growth']['total_customers']);
        self::assertSame(($before['growth']['approved_restaurants'] ?? 0) + 1, $after['growth']['approved_restaurants']);
        self::assertSame(($before['growth']['approved_riders'] ?? 0) + 1, $after['growth']['approved_riders']);
        self::assertSame(($before['growth']['new_signups_this_week']['customers'] ?? 0) + 1, $after['growth']['new_signups_this_week']['customers']);
        self::assertSame(($before['growth']['new_signups_this_week']['restaurants'] ?? 0) + 2, $after['growth']['new_signups_this_week']['restaurants']);
        self::assertSame(($before['growth']['new_signups_this_week']['riders'] ?? 0) + 2, $after['growth']['new_signups_this_week']['riders']);
        self::assertSame(($before['growth']['new_signups_this_week']['total'] ?? 0) + 5, $after['growth']['new_signups_this_week']['total']);
        self::assertNotEmpty($after['generated_at']);
    }

    public function testDashboardRejectsNonAdminUsers(): void
    {
        $this->client->request('GET', '/api/admin/dashboard', server: $this->auth($this->login(self::VIEWER_EMAIL)));
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function dashboard(string $token): array
    {
        $this->client->request('GET', '/api/admin/dashboard', server: $this->auth($token));
        self::assertResponseIsSuccessful();

        return $this->payload();
    }

    private function createAuthenticationFixture(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable('-2 weeks');
        $adminUser = $this->user('Dashboard Admin', self::ADMIN_EMAIL, '+639193000001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $viewerUser = $this->user('Dashboard Viewer', self::VIEWER_EMAIL, '+639193000002', UserRole::CUSTOMER, $now);
        $viewer = (new Customer())->setUser($viewerUser)->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $viewerUser, $viewer] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    private function createMetricFixture(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $customerUser = $this->user('Dashboard Customer', self::CUSTOMER_EMAIL, '+639193000003', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Dashboard Address')->setAddressLine('Dashboard Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);

        $restaurantUser = $this->user('Dashboard Restaurant Owner', self::RESTAURANT_EMAIL, '+639193000004', UserRole::RESTAURANT, $now);
        $restaurant = $this->restaurant($restaurantUser, 'Dashboard Restaurant', 'dashboard-restaurant', ApprovalStatus::APPROVED, RestaurantOperatingStatus::OPEN, $now);
        $pendingRestaurantUser = $this->user('Pending Dashboard Owner', self::PENDING_RESTAURANT_EMAIL, '+639193000005', UserRole::RESTAURANT, $now);
        $pendingRestaurant = $this->restaurant($pendingRestaurantUser, 'Pending Dashboard Restaurant', 'pending-dashboard-restaurant', ApprovalStatus::PENDING, RestaurantOperatingStatus::CLOSED, $now);

        $riderUser = $this->user('Dashboard Rider', self::RIDER_EMAIL, '+639193000006', UserRole::RIDER, $now);
        $rider = $this->rider($riderUser, ApprovalStatus::APPROVED, RiderAvailabilityStatus::AVAILABLE, $now);
        $pendingRiderUser = $this->user('Pending Dashboard Rider', self::PENDING_RIDER_EMAIL, '+639193000007', UserRole::RIDER, $now);
        $pendingRider = $this->rider($pendingRiderUser, ApprovalStatus::PENDING, RiderAvailabilityStatus::OFFLINE, $now);

        $delivered = $this->order('DASHBOARD-DELIVERED', $customer, $address, $restaurant, OrderStatus::DELIVERED, '150.00', $now->modify('-2 hours'))
            ->setDeliveredAt($now->modify('-1 hour'));
        $preparing = $this->order('DASHBOARD-PREPARING', $customer, $address, $restaurant, OrderStatus::PREPARING, '90.00', $now->modify('-90 minutes'));
        $cancelled = $this->order('DASHBOARD-CANCELLED', $customer, $address, $restaurant, OrderStatus::CANCELLED_BY_CUSTOMER, '75.00', $now->modify('-60 minutes'));
        $cancelledHistory = (new OrderStatusHistory())->setOrder($cancelled)->setStatus(OrderStatus::CANCELLED_BY_CUSTOMER->value)
            ->setChangedBy(OrderActor::CUSTOMER)->setNote('Dashboard test cancellation')->setCreatedAt($now->modify('-45 minutes'));

        $remittance = (new RiderCashRemittance())->setRider($rider)->setAmount('40.00')->setStatus(RemittanceStatus::PENDING)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $voucher = (new Voucher())->setCode('DASHBOARD-ACTIVE')->setScope(VoucherScope::PLATFORM)->setType(VoucherType::FIXED)
            ->setValue('10.00')->setIsActive(true)->setStartsAt($now->modify('-1 day'))->setEndsAt($now->modify('+1 day'))
            ->setCreatedAt($now)->setUpdatedAt($now);
        $redemption = (new VoucherRedemption())->setVoucher($voucher)->setOrder($delivered)->setCustomer($customer)
            ->setDiscountApplied('10.00')->setCreatedAt($now)->setUpdatedAt($now);
        $ticket = (new SupportTicket())->setUser($customerUser)->setSubject('Dashboard support ticket')->setMessage('Please help with this dashboard test.')
            ->setStatus(SupportTicketStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);

        foreach ([$customerUser, $customer, $address, $restaurantUser, $restaurant, $pendingRestaurantUser, $pendingRestaurant,
            $riderUser, $rider, $pendingRiderUser, $pendingRider, $delivered, $preparing, $cancelled, $cancelledHistory,
            $remittance, $voucher, $redemption, $ticket] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    private function restaurant(User $user, string $name, string $slug, ApprovalStatus $approval, RestaurantOperatingStatus $operating, \DateTimeImmutable $now): Restaurant
    {
        return (new Restaurant())->setUser($user)->setName($name)->setSlug($slug)->setAddress('Dashboard Restaurant Street')
            ->setLatitude('14.5995000')->setLongitude('120.9842000')->setApprovalStatus($approval)->setOperatingStatus($operating)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function rider(User $user, ApprovalStatus $approval, RiderAvailabilityStatus $availability, \DateTimeImmutable $now): Rider
    {
        return (new Rider())->setUser($user)->setVehicleType(VehicleType::MOTORCYCLE)->setPlateNumber('DASH-'.substr((string) $user->getPhone(), -3))
            ->setApprovalStatus($approval)->setAvailabilityStatus($availability)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function order(string $number, Customer $customer, CustomerAddress $address, Restaurant $restaurant, OrderStatus $status, string $total, \DateTimeImmutable $placedAt): Order
    {
        return (new Order())->setOrderNumber($number)->setCustomer($customer)->setCustomerAddress($address)->setRestaurant($restaurant)
            ->setStatus($status)->setSubtotal($total)->setDeliveryFee('0.00')->setTotalAmount($total)
            ->setPaymentMethod(PaymentMethod::COD)->setPlacedAt($placedAt)->setCreatedAt($placedAt)->setUpdatedAt($placedAt);
    }

    private function user(string $name, string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)->setStatus(UserStatus::ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function login(string $email): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        return (string) $this->payload()['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function assertMoneyDelta(string $expected, string $before, string $after): void
    {
        self::assertSame($expected, number_format((float) $after - (float) $before, 2, '.', ''));
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM voucher_redemptions WHERE voucher_id IN (SELECT id FROM vouchers WHERE code = 'DASHBOARD-ACTIVE')");
        $connection->executeStatement("DELETE FROM vouchers WHERE code = 'DASHBOARD-ACTIVE'");
        $connection->executeStatement("DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'DASHBOARD-%')");
        $connection->executeStatement("DELETE FROM orders WHERE order_number LIKE 'DASHBOARD-%'");
        $connection->executeStatement("DELETE FROM rider_cash_remittances WHERE rider_id IN (SELECT riders.id FROM riders JOIN users ON users.id = riders.user_id WHERE users.email = ?)", [self::RIDER_EMAIL]);
        $connection->executeStatement("DELETE FROM support_tickets WHERE subject = 'Dashboard support ticket'");
        $connection->executeStatement("DELETE FROM customer_addresses WHERE label = 'Dashboard Address'");
        $emails = [self::ADMIN_EMAIL, self::VIEWER_EMAIL, self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL, self::PENDING_RESTAURANT_EMAIL, self::RIDER_EMAIL, self::PENDING_RIDER_EMAIL];
        $placeholders = implode(', ', array_fill(0, count($emails), '?'));
        $connection->executeStatement("DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email IN ($placeholders))", $emails);
        $connection->executeStatement("DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email IN ($placeholders))", $emails);
        $connection->executeStatement("DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email IN ($placeholders))", $emails);
        $connection->executeStatement("DELETE FROM riders WHERE user_id IN (SELECT id FROM users WHERE email IN ($placeholders))", $emails);
        $connection->executeStatement("DELETE FROM users WHERE email IN ($placeholders)", $emails);
    }
}
