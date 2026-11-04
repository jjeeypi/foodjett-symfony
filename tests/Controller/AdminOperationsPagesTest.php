<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\SupportTicket;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Order reports, refunds and support tickets for admins. */
class AdminOperationsPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $adminId;
    private string $lateReportId;
    private string $rudeReportId;
    private string $paymentId;
    private string $ticketId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->createFixture();
    }

    public function testReportsAreResolvedOrRejectedWithTheAdminRecorded(): void
    {
        $crawler = $this->page('/admin/report?status=open&type=late_delivery');
        self::assertCount(1, $crawler->filter('#report-'.$this->lateReportId));
        self::assertCount(0, $crawler->filter('#report-'.$this->rudeReportId));

        $this->client->submit($crawler->filter('#report-'.$this->lateReportId)->selectButton('Resolve')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#report-'.$this->lateReportId, 'Give a reason.');

        $form = $this->page('/admin/report')->filter('#report-'.$this->lateReportId)->selectButton('Resolve')->form([
            'report_'.$this->lateReportId.'[reason]' => 'Delivery fee credited after review.',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/report');
        $report = $this->find(OrderReport::class, $this->lateReportId);
        self::assertSame(OrderReport::STATUS_RESOLVED, $report->getStatus());
        self::assertSame('Delivery fee credited after review.', $report->getResolution());
        self::assertSame($this->adminId, (string) $report->getResolvedByAdmin()?->getId());
        self::assertNotNull($report->getResolvedAt());

        $form = $this->page('/admin/report')->filter('#report-'.$this->rudeReportId)->selectButton('Reject report')->form([
            'report_'.$this->rudeReportId.'[reason]' => 'The chat log shows no rudeness.',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/report');
        self::assertSame(OrderReport::STATUS_REJECTED, $this->find(OrderReport::class, $this->rudeReportId)->getStatus());

        $crawler = $this->page('/admin/report');
        self::assertSelectorTextContains('#report-'.$this->lateReportId.' .status', 'Delivery fee credited after review.');
        self::assertCount(0, $crawler->filter('#report-'.$this->lateReportId.' form'));
        self::assertContains('order_report.resolved', $this->auditActions($this->lateReportId));
        self::assertContains('order_report.rejected', $this->auditActions($this->rudeReportId));
    }

    public function testPartialThenRemainingRefund(): void
    {
        $row = '#payment-'.$this->paymentId;
        $this->client->submit($this->page('/admin/payment?method=gcash')->filter($row)->selectButton('Refund')->form([
            'refund_'.$this->paymentId.'[amount]' => '200',
        ]));
        self::assertResponseRedirects('/admin/payment');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The refund amount cannot exceed the remaining refundable balance.');
        self::assertSame(Payment::STATUS_PAID, $this->find(Payment::class, $this->paymentId)->getStatus());

        $this->client->submit($this->page('/admin/payment')->filter($row)->selectButton('Refund')->form([
            'refund_'.$this->paymentId.'[amount]' => 'abc',
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->submit($this->page('/admin/payment')->filter($row)->selectButton('Refund')->form([
            'refund_'.$this->paymentId.'[amount]' => '25',
            'refund_'.$this->paymentId.'[reason]' => 'Missing drink.',
        ]));
        self::assertResponseRedirects('/admin/payment');
        $payment = $this->find(Payment::class, $this->paymentId);
        self::assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $payment->getStatus());
        self::assertSame('25.00', $payment->getRefundedAmount());

        $this->client->submit($this->page('/admin/payment')->filter($row)->selectButton('Refund')->form());
        self::assertResponseRedirects('/admin/payment');
        $payment = $this->find(Payment::class, $this->paymentId);
        self::assertSame(Payment::STATUS_REFUNDED, $payment->getStatus());
        self::assertSame('125.00', $payment->getRefundedAmount());
        self::assertCount(2, $payment->getStatusHistory());
        self::assertSame(['payment.refunded', 'payment.refunded'], $this->auditActions($this->paymentId));

        $this->page('/admin/payment');
        self::assertSelectorTextContains($row.' .refunded', '₱125.00');
        self::assertSelectorNotExists($row.' form');
    }

    public function testSupportTicketsMoveBetweenStatuses(): void
    {
        $crawler = $this->page('/admin/support?status=open');
        self::assertSelectorTextContains('#ticket-'.$this->ticketId, 'Where is my refund?');
        $this->client->submit($crawler->filter('#ticket-'.$this->ticketId)->selectButton('Mark in progress')->form());
        self::assertResponseRedirects('/admin/support');
        self::assertSame(SupportTicket::STATUS_IN_PROGRESS, $this->find(SupportTicket::class, $this->ticketId)->getStatus());

        $this->client->submit($this->page('/admin/support')->filter('#ticket-'.$this->ticketId)->selectButton('Mark closed')->form());
        self::assertSame(SupportTicket::STATUS_CLOSED, $this->find(SupportTicket::class, $this->ticketId)->getStatus());
        self::assertCount(0, $this->page('/admin/support?status=open')->filter('#ticket-'.$this->ticketId));
        self::assertSame(['support_ticket.status_updated', 'support_ticket.status_updated'], $this->auditActions($this->ticketId));

        $this->client->request('POST', '/admin/support/'.$this->ticketId.'/status/open', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    private function createFixture(): void
    {
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Operations Admin', 'operations-admin@foodjett.test', '+639190300001', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Operations Customer', 'operations-customer@foodjett.test', '+639190300002', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Operations Address')->setAddressLine('Operations Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Operations Owner', 'operations-restaurant@foodjett.test', '+639190300003', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())->setUser($restaurantUser)->setName('Operations Restaurant')->setSlug('operations-restaurant')
            ->setAddress('Operations Street')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $order = (new Order())->setOrderNumber('OPERATIONS-ORDER')->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)
            ->setStatus(Order::STATUS_DELIVERED)->setSubtotal('100.00')->setDeliveryFee('25.00')->setTotalAmount('125.00')
            ->setPaymentMethod(Payment::METHOD_GCASH)->setPlacedAt($now->modify('-1 hour'))->setDeliveredAt($now)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $payment = (new Payment())->setOrder($order)->setMethod(Payment::METHOD_GCASH)->setStatus(Payment::STATUS_PAID)->setAmount('125.00')
            ->setPaidAt($now)->setTransactionReference('SIMULATED-OPERATIONS')->setCreatedAt($now)->setUpdatedAt($now);
        $order->setPayment($payment);
        $late = $this->report($order, $customerUser, OrderReport::AGAINST_PLATFORM, OrderReport::TYPE_LATE_DELIVERY, 'The delivery was late.', $now);
        $rude = $this->report($order, $customerUser, OrderReport::AGAINST_RIDER, OrderReport::TYPE_RUDE_BEHAVIOR, 'The rider was rude.', $now);
        $ticket = (new SupportTicket())->setUser($customerUser)->setSubject('Where is my refund?')->setMessage('I was promised a refund yesterday.')
            ->setStatus(SupportTicket::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);

        foreach ([$adminUser, $admin, $customerUser, $customer, $address, $restaurantUser, $restaurant, $order, $payment, $late, $rude, $ticket] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->adminId = (string) $admin->getId();
        $this->lateReportId = (string) $late->getId();
        $this->rudeReportId = (string) $rude->getId();
        $this->paymentId = (string) $payment->getId();
        $this->ticketId = (string) $ticket->getId();
        $this->client->loginUser($adminUser, 'main');
    }

    private function report(Order $order, User $by, string $against, string $type, string $description, \DateTimeImmutable $now): OrderReport
    {
        return (new OrderReport())->setOrder($order)->setReportedBy($by)->setAgainst($against)->setType($type)
            ->setDescription($description)->setStatus(OrderReport::STATUS_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function user(string $name, string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)
            ->setPassword(password_hash('TestPassword123!', PASSWORD_BCRYPT, ['cost' => 4]))->setRole($role)->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function page(string $url): Crawler
    {
        $this->entityManager()->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, string $id): object
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find($class, $id) ?? self::fail($class.' '.$id.' is missing.');
    }

    /** @return list<string> */
    private function auditActions(string $subjectId): array
    {
        return $this->entityManager()->getRepository(AuditLog::class)->createQueryBuilder('log')->select('log.action')
            ->andWhere('log.subjectId = :id')->setParameter('id', $subjectId)->getQuery()->getSingleColumnResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
