<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\RiderDocument;
use App\Entity\RiderEarning;
use App\Entity\RiderPayout;
use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/** The rider's profile, documents, earnings and cash remittances. */
class RiderAccountPagesTest extends RiderWebTestCase
{
    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->riderUser, 'main');
        $this->pdf = sys_get_temp_dir().'/foodjett-license.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n%test\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([self::getContainer()->getParameter('app.private_upload_dir'), $this->pdf]);
        parent::tearDown();
    }

    public function testProfileSavesTheVehicleAndEncryptsThePayoutAccount(): void
    {
        $this->submit('/rider/account', 'Save profile', [
            'rider_profile_form[name]' => 'Updated Rider',
            'rider_profile_form[phone]' => '+639170001111',
            'rider_profile_form[vehicleType]' => Rider::VEHICLE_CAR,
            'rider_profile_form[plateNumber]' => 'CAR-777',
            'rider_profile_form[payoutMethod]' => Rider::PAYOUT_METHOD_EWALLET,
            'rider_profile_form[payoutAccountName]' => 'GCash Rider',
            'rider_profile_form[payoutAccountNumber]' => '09171234567',
        ]);
        self::assertResponseRedirects('/rider/account');

        $this->entityManager->clear();
        $rider = $this->entityManager->find(Rider::class, $this->rider->getId());
        self::assertSame('Updated Rider', $rider->getUser()->getName());
        self::assertSame(Rider::VEHICLE_CAR, $rider->getVehicleType());
        $raw = (string) $this->entityManager->getConnection()->fetchOne('SELECT payout_account_details FROM riders WHERE id = ?', [$rider->getId()]);
        self::assertStringNotContainsString('09171234567', $raw);

        $crawler = $this->page('/rider/account');
        self::assertSame('09171234567', $crawler->filter('#rider_profile_form_payoutAccountNumber')->attr('value'), 'Decrypted for its owner.');
    }

    public function testAPlateIsRequiredExceptForBicycles(): void
    {
        $this->submit('/rider/account', 'Save profile', ['rider_profile_form[vehicleType]' => Rider::VEHICLE_CAR, 'rider_profile_form[plateNumber]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A plate number is required for this vehicle type.');

        $this->submit('/rider/account', 'Save profile', ['rider_profile_form[vehicleType]' => Rider::VEHICLE_BICYCLE, 'rider_profile_form[plateNumber]' => '']);
        self::assertResponseRedirects('/rider/account');
    }

    public function testDeletingTheAccountAnonymizesItButKeepsDeliveries(): void
    {
        $order = $this->createOrder(Order::STATUS_DELIVERED, $this->rider);
        $this->entityManager->flush();

        $this->submit('/rider/account', 'Delete my account');
        self::assertResponseRedirects('/');

        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $this->riderUser->getId());
        self::assertSame('Deleted Rider', $user->getName());
        self::assertNull($user->getEmail());
        self::assertSame(User::STATUS_BANNED, $user->getStatus());
        self::assertNull($user->getRider()->getEncryptedPayoutAccountDetails());
        self::assertNotNull($this->entityManager->find(Order::class, $order->getId()));
        $this->client->request('GET', '/rider');
        self::assertResponseRedirects('/login');
    }

    public function testDocumentsAreUploadedAndOnlyRejectedOnesReplaced(): void
    {
        $now = new \DateTimeImmutable();
        $foreign = (new RiderDocument())->setRider($this->otherRider)->setType(RiderDocument::TYPE_VALID_ID)
            ->setFilePath('riders/documents/foreign.pdf')->setStatus(RiderDocument::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($foreign);
        $this->entityManager->flush();

        $form = $this->page('/rider/documents')->selectButton('Upload')->form(['document_upload_form[type]' => RiderDocument::TYPE_DRIVERS_LICENSE]);
        $form['document_upload_form[file]']->upload($this->pdf);
        $this->client->submit($form);
        self::assertResponseRedirects('/rider/documents');

        $crawler = $this->page('/rider/documents');
        self::assertSelectorTextContains('#document-drivers_license', 'pending');
        self::assertCount(0, $crawler->filter('#document_upload_form_type option[value="drivers_license"]'));
        self::assertSelectorTextContains('#document-valid_id', 'missing', 'Another rider\'s document is not listed.');
        $this->client->request('GET', '/rider/documents/'.$foreign->getId().'/file');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEarningsAndPayoutsAreScopedToTheRider(): void
    {
        $now = new \DateTimeImmutable();
        $this->earning($this->rider, '50.00', $now);
        $earlier = $this->earning($this->rider, '70.00', $now->modify('-2 days'));
        $this->earning($this->otherRider, '999.00', $now);
        $this->persist(
            (new RiderPayout())->setRider($this->rider)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
                ->setTotalAmount('120.00')->setStatus(RiderPayout::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now),
            (new RiderPayout())->setRider($this->otherRider)->setPeriodStart($now->modify('-7 days'))->setPeriodEnd($now)
                ->setTotalAmount('999.00')->setStatus(RiderPayout::STATUS_PAID)->setPaidAt($now)->setCreatedAt($now)->setUpdatedAt($now),
        );
        $this->entityManager->flush();

        $crawler = $this->page('/rider/finance');
        $totals = array_combine($crawler->filter('#totals dt')->each(fn ($dt) => $dt->text()), $crawler->filter('#totals dd')->each(fn ($dd) => $dd->text()));
        self::assertSame('PHP 50.00', $totals['Today']);
        $week = $earlier->getCreatedAt() >= $now->setTime(0, 0)->modify('monday this week') ? '120.00' : '50.00';
        self::assertSame('PHP '.$week, $totals['This week']);
        self::assertCount(2, $crawler->filter('#earnings tbody tr'));
        self::assertSelectorTextNotContains('#earnings', '999.00');
        self::assertSelectorTextContains('#payouts', 'PHP 120.00');
        self::assertSelectorTextNotContains('#payouts', '999.00');
    }

    public function testRemittancesCannotExceedTheCashLeftAfterPendingRequests(): void
    {
        $now = new \DateTimeImmutable();
        $this->rider->setCashOnHand('500.00')->setCashRemitLimit('1000.00');
        $this->persist(
            (new RiderCashRemittance())->setRider($this->rider)->setAmount('100.00')->setReferenceNote('First deposit')
                ->setStatus(RiderCashRemittance::STATUS_PENDING)->setCreatedAt($now->modify('-1 day'))->setUpdatedAt($now->modify('-1 day')),
            (new RiderCashRemittance())->setRider($this->otherRider)->setAmount('50.00')->setStatus(RiderCashRemittance::STATUS_PENDING)
                ->setCreatedAt($now)->setUpdatedAt($now),
        );
        $this->entityManager->flush();

        $crawler = $this->page('/rider/finance');
        self::assertSame('PHP 400.00', $crawler->filter('#cash dt:contains("Available to remit") + dd')->text());

        $this->submit('/rider/finance', 'Request remittance', ['remittance_form[amount]' => '450', 'remittance_form[referenceNote]' => 'Too much']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'more than the cash you hold');

        $this->submit('/rider/finance', 'Request remittance', ['remittance_form[amount]' => '300', 'remittance_form[referenceNote]' => 'Second deposit']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Remittance request sent.');
        self::assertCount(2, $this->client->getCrawler()->filter('#remittances li'));
        self::assertSelectorTextNotContains('#remittances', 'PHP 50.00');
    }

    private function earning(Rider $rider, string $total, \DateTimeImmutable $at): RiderEarning
    {
        $order = $this->createOrder(Order::STATUS_DELIVERED, $rider, at: $at)->setDeliveredAt($at);
        $earning = (new RiderEarning())->setRider($rider)->setOrder($order)->setBasePay('40.00')->setDistancePay('0.00')
            ->setWaitingPay('0.00')->setIncentivePay('0.00')->setTipAmount('10.00')->setTotalEarned($total)->setCreatedAt($at)->setUpdatedAt($at);
        $order->setRiderEarning($earning);
        $this->persist($earning);

        return $earning;
    }

    private function page(string $url): Crawler
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @param array<string, string> $fields */
    private function submit(string $page, string $button, array $fields = []): void
    {
        $this->client->submit($this->page($page)->selectButton($button)->form($fields));
    }
}
