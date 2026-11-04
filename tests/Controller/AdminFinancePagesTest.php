<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\RestaurantPayout;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\RiderEarning;
use App\Entity\RiderPayout;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Cash remittances and restaurant and rider payouts for admins. */
class AdminFinancePagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $restaurantId;
    private string $riderId;
    private string $remittanceId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->createFixture();
    }

    public function testRestaurantPayoutIsGeneratedOnceAndMarkedPaid(): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->generate('/admin/payout/restaurant', $today, $today);
        self::assertResponseRedirects('/admin/payout/restaurant');

        $payout = $this->entityManager()->getRepository(RestaurantPayout::class)->findOneBy(['restaurant' => $this->restaurantId]) ?? self::fail('No payout was created.');
        self::assertSame('100.00', $payout->getGrossSales());
        self::assertSame('15.00', $payout->getCommissionDeducted());
        self::assertSame('85.00', $payout->getNetAmount());
        self::assertSame(RestaurantPayout::STATUS_PENDING, $payout->getStatus());
        $payoutId = (string) $payout->getId();

        $this->generate('/admin/payout/restaurant', $today, $today);
        self::assertSame(1, $this->entityManager()->getRepository(RestaurantPayout::class)->count(['restaurant' => $this->restaurantId]));

        $crawler = $this->page('/admin/payout/restaurant');
        self::assertSelectorTextContains('#payout-'.$payoutId.' .net', 'PHP 85.00');
        $this->client->submit($crawler->filter('#payout-'.$payoutId)->selectButton('Mark paid')->form());
        self::assertResponseRedirects('/admin/payout/restaurant');
        $payout = $this->find(RestaurantPayout::class, $payoutId);
        self::assertSame(RestaurantPayout::STATUS_PAID, $payout->getStatus());
        self::assertNotNull($payout->getPaidAt());
        self::assertSame(['restaurant_payout.generated', 'restaurant_payout.paid'], $this->auditActions($payoutId));
    }

    public function testRiderPayoutSumsEarningsAndIsMarkedPaid(): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->generate('/admin/payout/rider', $today, $today);
        self::assertResponseRedirects('/admin/payout/rider');

        $payout = $this->entityManager()->getRepository(RiderPayout::class)->findOneBy(['rider' => $this->riderId]) ?? self::fail('No payout was created.');
        self::assertSame('75.00', $payout->getTotalAmount());
        $payoutId = (string) $payout->getId();

        $this->client->submit($this->page('/admin/payout/rider')->filter('#payout-'.$payoutId)->selectButton('Mark paid')->form());
        self::assertSame(RiderPayout::STATUS_PAID, $this->find(RiderPayout::class, $payoutId)->getStatus());
    }

    public function testPayoutPeriodMustBeInThePastAndInOrder(): void
    {
        $this->generate('/admin/payout/restaurant', (new \DateTimeImmutable('+1 day'))->format('Y-m-d'), (new \DateTimeImmutable('+2 days'))->format('Y-m-d'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The period cannot end in the future.');

        $this->generate('/admin/payout/rider', (new \DateTimeImmutable('-1 day'))->format('Y-m-d'), (new \DateTimeImmutable('-3 days'))->format('Y-m-d'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The period must end on or after its first day.');

        $this->generate('/admin/payout/rider', '2020-01-01', '2020-01-31');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No rider earned anything in this period.');
    }

    public function testRemittanceConfirmationLowersCashOnlyOnce(): void
    {
        $crawler = $this->page('/admin/remittance?status=pending');
        $form = $crawler->filter('#remittance-'.$this->remittanceId)->selectButton('Confirm received')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/remittance');
        $remittance = $this->find(RiderCashRemittance::class, $this->remittanceId);
        self::assertSame(RiderCashRemittance::STATUS_CONFIRMED, $remittance->getStatus());
        self::assertSame('300.00', $remittance->getRider()->getCashOnHand());
        self::assertNotNull($remittance->getConfirmedByAdmin());

        $this->client->submit($form);
        self::assertSame('300.00', $this->find(Rider::class, $this->riderId)->getCashOnHand());
        self::assertSame(['rider_remittance.confirmed'], $this->auditActions($this->remittanceId));

        $this->page('/admin/remittance');
        self::assertSelectorTextContains('#remittance-'.$this->remittanceId.' .cash', 'PHP 300.00');
        self::assertSelectorNotExists('#remittance-'.$this->remittanceId.' form');
    }

    private function generate(string $url, string $from, string $to): void
    {
        $this->client->submit($this->page($url)->selectButton('Generate payouts')->form([
            'admin_payout_period_form[periodStart]' => $from,
            'admin_payout_period_form[periodEnd]' => $to,
        ]));
    }

    private function createFixture(): void
    {
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $adminUser = $this->user('Finance Admin', 'finance-admin@foodjett.test', '+639190400001', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Finance Owner', 'finance-restaurant@foodjett.test', '+639190400002', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())->setUser($restaurantUser)->setName('Finance Restaurant')->setSlug('finance-restaurant')
            ->setAddress('Finance Street')->setLatitude('14.5995000')->setLongitude('120.9842000')->setCommissionRate('15.00')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $riderUser = $this->user('Finance Rider', 'finance-rider@foodjett.test', '+639190400003', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setApprovalStatus(Rider::APPROVAL_APPROVED)
            ->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)->setCashOnHand('500.00')->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Finance Customer', 'finance-customer@foodjett.test', '+639190400004', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Finance Address')->setAddressLine('Finance Delivery')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $order = (new Order())->setOrderNumber('ADMIN-FINANCE-ORDER')->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)->setRider($rider)
            ->setStatus(Order::STATUS_DELIVERED)->setSubtotal('100.00')->setDeliveryFee('15.00')->setTotalAmount('115.00')->setCommissionAmount('15.00')
            ->setPaymentMethod(Payment::METHOD_CARD)->setPlacedAt($now->modify('-1 hour'))->setDeliveredAt($now)->setCreatedAt($now)->setUpdatedAt($now);
        $earning = (new RiderEarning())->setRider($rider)->setOrder($order)->setBasePay('40.00')->setDistancePay('25.00')
            ->setWaitingPay('0.00')->setIncentivePay('10.00')->setTipAmount('0.00')->setTotalEarned('75.00')->setCreatedAt($now)->setUpdatedAt($now);
        $remittance = (new RiderCashRemittance())->setRider($rider)->setAmount('200.00')->setReferenceNote('Deposit slip')
            ->setStatus(RiderCashRemittance::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$adminUser, $admin, $restaurantUser, $restaurant, $riderUser, $rider, $customerUser, $customer, $address, $order, $earning, $remittance] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->restaurantId = (string) $restaurant->getId();
        $this->riderId = (string) $rider->getId();
        $this->remittanceId = (string) $remittance->getId();
        $this->client->loginUser($adminUser, 'main');
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
            ->andWhere('log.subjectId = :id')->setParameter('id', $subjectId)->orderBy('log.id')->getQuery()->getSingleColumnResult();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
