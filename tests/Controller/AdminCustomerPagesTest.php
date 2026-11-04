<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Customers for admins: search, order summary, suspension and reactivation. */
class AdminCustomerPagesTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';

    private KernelBrowser $client;
    private string $customerId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Customer Admin', 'customer-admin@foodjett.test', '+639190200001', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Managed Customer', 'managed-customer@foodjett.test', '+639190200002', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Managed Address')->setAddressLine('Managed Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Report Owner', 'report-restaurant@foodjett.test', '+639190200003', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())->setUser($restaurantUser)->setName('Report Restaurant')->setSlug('report-restaurant')
            ->setAddress('Report Street')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $order = (new Order())->setOrderNumber('ADMIN-REPORT-ORDER')->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)
            ->setStatus(Order::STATUS_DELIVERED)->setSubtotal('100.00')->setDeliveryFee('25.00')->setTotalAmount('125.00')
            ->setPaymentMethod(Payment::METHOD_COD)->setPlacedAt($now->modify('-1 hour'))->setDeliveredAt($now)
            ->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $customerUser, $customer, $address, $restaurantUser, $restaurant, $order] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $this->customerId = (string) $customer->getId();
        $this->client->loginUser($adminUser, 'main');
    }

    public function testCustomerSearchDetailSuspendAndReactivate(): void
    {
        $crawler = $this->page('/admin/customer?search=Managed%20Customer');
        self::assertCount(1, $crawler->filter('tbody tr[id^="customer-"]'));
        self::assertSelectorTextContains('#customer-'.$this->customerId, 'managed-customer@foodjett.test');

        $url = '/admin/customer/'.$this->customerId;
        $crawler = $this->page($url);
        self::assertSelectorTextContains('#order-summary', '1 orders, ₱125.00 spent');
        self::assertSelectorTextContains('ul', 'ADMIN-REPORT-ORDER');

        $this->client->submit($crawler->selectButton('Suspend account')->form());
        self::assertResponseRedirects($url);
        self::assertSame(User::STATUS_SUSPENDED, $this->customer()->getUser()->getStatus());

        $this->client->submit($this->page($url)->selectButton('Reactivate account')->form());
        self::assertResponseRedirects($url);
        self::assertSame(User::STATUS_ACTIVE, $this->customer()->getUser()->getStatus());

        $actions = $this->entityManager()->getRepository(AuditLog::class)->createQueryBuilder('log')->select('log.action')
            ->andWhere('log.subjectId = :id')->setParameter('id', $this->customerId)->getQuery()->getSingleColumnResult();
        self::assertContains('customer.suspended', $actions);
        self::assertContains('customer.reactivated', $actions);
    }

    public function testAForgedAccountTokenIsRefused(): void
    {
        $this->client->request('POST', '/admin/customer/'.$this->customerId.'/account/suspended', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(User::STATUS_ACTIVE, $this->customer()->getUser()->getStatus());
    }

    private function customer(): Customer
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(Customer::class, $this->customerId) ?? self::fail('The customer is missing.');
    }

    private function page(string $url): Crawler
    {
        $this->entityManager()->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
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
