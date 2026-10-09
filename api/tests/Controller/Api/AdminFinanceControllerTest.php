<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\RiderEarning;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\RemittanceStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\VehicleType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminFinanceControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'finance-admin@foodjett.test';
    private const RESTAURANT_EMAIL = 'finance-restaurant@foodjett.test';
    private const RIDER_EMAIL = 'finance-rider@foodjett.test';
    private const CUSTOMER_EMAIL = 'finance-customer@foodjett.test';

    private KernelBrowser $client;
    private string $paymentId;
    private string $remittanceId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        [$this->paymentId, $this->remittanceId] = $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testAdminCanRecordPartialAndFullRefundWithHistory(): void
    {
        $token = $this->login();
        $this->client->jsonRequest('POST', '/api/admin/payments/'.$this->paymentId.'/refund', ['amount' => 25, 'reason' => 'Missing item'], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('partially_refunded', $this->payload()['payment']['status']);
        self::assertSame('25.00', $this->payload()['payment']['refunded_amount']);

        $this->client->jsonRequest('POST', '/api/admin/payments/'.$this->paymentId.'/refund', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('refunded', $this->payload()['payment']['status']);
        self::assertSame('115.00', $this->payload()['payment']['refunded_amount']);

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_status_history WHERE payment_id = ?', [$this->paymentId]));
        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.refunded' AND subject_id = ?", [$this->paymentId]));
    }

    public function testAdminGeneratesAndPaysRestaurantAndRiderPayouts(): void
    {
        $token = $this->login();
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $period = ['period_start' => $today, 'period_end' => $today];

        $this->client->jsonRequest('POST', '/api/admin/restaurant-payouts/generate', $period, server: $this->auth($token));
        self::assertResponseStatusCodeSame(201);
        $restaurantPayout = $this->payload()['payouts'][0];
        self::assertSame('100.00', $restaurantPayout['gross_sales']);
        self::assertSame('15.00', $restaurantPayout['commission_deducted']);
        self::assertSame('85.00', $restaurantPayout['net_amount']);
        $this->client->jsonRequest('POST', '/api/admin/restaurant-payouts/'.$restaurantPayout['id'].'/mark-paid', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('paid', $this->payload()['payout']['status']);

        $this->client->jsonRequest('POST', '/api/admin/rider-payouts/generate', $period, server: $this->auth($token));
        self::assertResponseStatusCodeSame(201);
        $riderPayout = $this->payload()['payouts'][0];
        self::assertSame('75.00', $riderPayout['total_amount']);
        $this->client->jsonRequest('POST', '/api/admin/rider-payouts/'.$riderPayout['id'].'/mark-paid', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('paid', $this->payload()['payout']['status']);
    }

    public function testRemittanceConfirmationLocksAndDecrementsCashOnlyOnce(): void
    {
        $token = $this->login();
        $url = '/api/admin/rider-cash-remittances/'.$this->remittanceId.'/confirm';
        $this->client->jsonRequest('POST', $url, [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('confirmed', $this->payload()['remittance']['status']);
        self::assertSame('300.00', $this->payload()['remittance']['rider']['cash_on_hand']);

        $this->client->jsonRequest('POST', $url, [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('300.00', $this->payload()['remittance']['rider']['cash_on_hand']);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'rider_remittance.confirmed' AND subject_id = ?", [$this->remittanceId]));
    }

    /** @return array{string, string} */
    private function createFixture(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Finance Admin', self::ADMIN_EMAIL, '+639190300001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Finance Owner', self::RESTAURANT_EMAIL, '+639190300002', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())->setUser($restaurantUser)->setName('Finance Restaurant')->setSlug('finance-restaurant')
            ->setAddress('Finance Street')->setLatitude('14.5995000')->setLongitude('120.9842000')->setCommissionRate('15.00')
            ->setApprovalStatus(ApprovalStatus::APPROVED)->setOperatingStatus(RestaurantOperatingStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $riderUser = $this->user('Finance Rider', self::RIDER_EMAIL, '+639190300003', UserRole::RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(VehicleType::MOTORCYCLE)->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setAvailabilityStatus(RiderAvailabilityStatus::OFFLINE)->setCashOnHand('500.00')->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Finance Customer', self::CUSTOMER_EMAIL, '+639190300004', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Finance Address')->setAddressLine('Finance Delivery')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $order = (new Order())->setOrderNumber('ADMIN-FINANCE-ORDER')->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)->setRider($rider)
            ->setStatus(OrderStatus::DELIVERED)->setSubtotal('100.00')->setDeliveryFee('15.00')->setTotalAmount('115.00')->setCommissionAmount('15.00')
            ->setPaymentMethod(PaymentMethod::CARD)->setPlacedAt($now->modify('-1 hour'))->setDeliveredAt($now)->setCreatedAt($now)->setUpdatedAt($now);
        $payment = (new Payment())->setOrder($order)->setMethod(PaymentMethod::CARD)->setStatus(PaymentStatus::PAID)->setAmount('115.00')
            ->setTransactionReference('SIMULATED-FINANCE')->setPaidAt($now)->setCreatedAt($now)->setUpdatedAt($now);
        $order->setPayment($payment);
        $earning = (new RiderEarning())->setRider($rider)->setOrder($order)->setBasePay('40.00')->setDistancePay('25.00')
            ->setWaitingPay('0.00')->setIncentivePay('10.00')->setTipAmount('0.00')->setTotalEarned('75.00')->setCreatedAt($now)->setUpdatedAt($now);
        $remittance = (new RiderCashRemittance())->setRider($rider)->setAmount('200.00')->setReferenceNote('Deposit slip')
            ->setStatus(RemittanceStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $restaurantUser, $restaurant, $riderUser, $rider, $customerUser, $customer, $address, $order, $payment, $earning, $remittance] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        return [(string) $payment->getId(), (string) $remittance->getId()];
    }

    private function user(string $name, string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)->setStatus(UserStatus::ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function login(): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::ADMIN_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        return (string) $this->payload()['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array { return ['HTTP_AUTHORIZATION' => 'Bearer '.$token]; }
    /** @return array<string, mixed> */
    private function payload(): array { return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM audit_logs WHERE action LIKE 'payment.%' OR action LIKE 'restaurant_payout.%' OR action LIKE 'rider_payout.%' OR action LIKE 'rider_remittance.%'");
        $connection->executeStatement("DELETE FROM restaurant_payouts WHERE restaurant_id IN (SELECT id FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?))", [self::RESTAURANT_EMAIL]);
        $connection->executeStatement("DELETE FROM rider_payouts WHERE rider_id IN (SELECT id FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?))", [self::RIDER_EMAIL]);
        $connection->executeStatement("DELETE FROM rider_cash_remittances WHERE rider_id IN (SELECT id FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?))", [self::RIDER_EMAIL]);
        $connection->executeStatement("DELETE FROM rider_earnings WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'ADMIN-FINANCE-ORDER')");
        $connection->executeStatement("DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'ADMIN-FINANCE-ORDER'))");
        $connection->executeStatement("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'ADMIN-FINANCE-ORDER')");
        $connection->executeStatement("DELETE FROM orders WHERE order_number = 'ADMIN-FINANCE-ORDER'");
        $connection->executeStatement("DELETE FROM customer_addresses WHERE label = 'Finance Address'");
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::ADMIN_EMAIL]);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM riders WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RIDER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?, ?)', [self::ADMIN_EMAIL, self::RESTAURANT_EMAIL, self::RIDER_EMAIL, self::CUSTOMER_EMAIL]);
    }
}
