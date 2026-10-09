<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderReportAgainst;
use App\Enum\OrderReportStatus;
use App\Enum\OrderReportType;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCustomerReportControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'customer-admin@foodjett.test';
    private const CUSTOMER_EMAIL = 'managed-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'report-restaurant@foodjett.test';

    private KernelBrowser $client;
    private string $customerId;
    private string $reportId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        [$this->customerId, $this->reportId] = $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCustomerSearchDetailSuspendAndReactivate(): void
    {
        $token = $this->login();
        $this->client->request('GET', '/api/admin/customers?search=Managed Customer', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame($this->customerId, (string) ($this->payload()['data'][0]['id'] ?? ''));

        $this->client->request('GET', '/api/admin/customers/'.$this->customerId, server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['customer']['order_summary']['order_count']);
        self::assertSame('125.00', $this->payload()['customer']['order_summary']['lifetime_spend']);

        $this->client->jsonRequest('POST', '/api/admin/customers/'.$this->customerId.'/suspend', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->payload()['customer']['status']);
        $this->client->jsonRequest('POST', '/api/admin/customers/'.$this->customerId.'/reactivate', [], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['customer']['status']);
    }

    public function testReportQueueCanResolveAndRejectWithAdminAttribution(): void
    {
        $token = $this->login();
        $this->client->request('GET', '/api/admin/order-reports?status=open&type=late_delivery', server: $this->auth($token));
        self::assertResponseIsSuccessful();
        self::assertSame($this->reportId, (string) ($this->payload()['data'][0]['id'] ?? ''));

        $this->client->jsonRequest('POST', '/api/admin/order-reports/'.$this->reportId.'/resolve', [], server: $this->auth($token));
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/admin/order-reports/'.$this->reportId.'/resolve', ['resolution' => 'Delivery fee credited after review.'], server: $this->auth($token));
        self::assertResponseIsSuccessful();
        $report = $this->payload()['report'] ?? [];
        self::assertSame('resolved', $report['status'] ?? null);
        self::assertNotNull($report['resolved_by_admin_id'] ?? null);
        self::assertNotNull($report['resolved_at'] ?? null);
    }

    /** @return array{string, string} */
    private function createFixture(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Customer Admin', self::ADMIN_EMAIL, '+639190200001', UserRole::ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Managed Customer', self::CUSTOMER_EMAIL, '+639190200002', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Managed Address')->setAddressLine('Managed Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Report Owner', self::RESTAURANT_EMAIL, '+639190200003', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())->setUser($restaurantUser)->setName('Report Restaurant')->setSlug('report-restaurant')
            ->setAddress('Report Street')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $order = (new Order())->setOrderNumber('ADMIN-REPORT-ORDER')->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)
            ->setStatus(OrderStatus::DELIVERED)->setSubtotal('100.00')->setDeliveryFee('25.00')->setTotalAmount('125.00')
            ->setPaymentMethod(PaymentMethod::COD)->setPlacedAt($now->modify('-1 hour'))->setDeliveredAt($now)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $report = (new OrderReport())->setOrder($order)->setReportedBy($customerUser)->setAgainst(OrderReportAgainst::PLATFORM)
            ->setType(OrderReportType::LATE_DELIVERY)->setDescription('The delivery was late.')->setStatus(OrderReportStatus::OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $customerUser, $customer, $address, $restaurantUser, $restaurant, $order, $report] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [(string) $customer->getId(), (string) $report->getId()];
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
        $connection->executeStatement("DELETE FROM audit_logs WHERE action LIKE 'customer.%' OR action LIKE 'order_report.%'");
        $connection->executeStatement("DELETE FROM order_reports WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'ADMIN-REPORT-ORDER')");
        $connection->executeStatement("DELETE FROM orders WHERE order_number = 'ADMIN-REPORT-ORDER'");
        $connection->executeStatement("DELETE FROM customer_addresses WHERE label = 'Managed Address'");
        $connection->executeStatement('DELETE FROM admins WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::ADMIN_EMAIL]);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?)', [self::ADMIN_EMAIL, self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
