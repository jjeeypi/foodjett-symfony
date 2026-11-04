<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\RestaurantOperatingHour;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

/** Restaurant profile, open/closed switch, weekly hours and documents. */
class RestaurantProfilePagesTest extends RestaurantWebTestCase
{
    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->restaurantUser, 'main');
        $this->pdf = sys_get_temp_dir().'/foodjett-permit.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n%test\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([
            self::getContainer()->getParameter('app.private_upload_dir'),
            self::getContainer()->getParameter('app.public_upload_dir'),
            $this->pdf,
        ]);
        parent::tearDown();
    }

    public function testProfileUpdateSavesDetailsPhoneAndPayoutAccount(): void
    {
        $crawler = $this->page('/restaurant/profile');
        self::assertSame($this->restaurantUser->getPhone(), $crawler->filter('#restaurant_profile_form_phone')->attr('value'));

        $this->client->submit($crawler->selectButton('Save profile')->form([
            'restaurant_profile_form[name]' => 'Renamed Kitchen',
            'restaurant_profile_form[cuisineType]' => 'Filipino',
            'restaurant_profile_form[description]' => 'Home-style cooking.',
            'restaurant_profile_form[phone]' => '+639171112222',
            'restaurant_profile_form[defaultPrepTimeMinutes]' => '25',
            'restaurant_profile_form[minOrderAmount]' => '150',
            'restaurant_profile_form[payoutMethod]' => Restaurant::PAYOUT_METHOD_EWALLET,
            'restaurant_profile_form[payoutAccountName]' => 'Renamed Kitchen',
            'restaurant_profile_form[payoutAccountNumber]' => '09171112222',
        ]));
        self::assertResponseRedirects('/restaurant/profile');

        $restaurant = $this->fresh(Restaurant::class, (string) $this->restaurant->getId());
        self::assertSame('Renamed Kitchen', $restaurant->getName());
        self::assertSame(25, $restaurant->getDefaultPrepTimeMinutes());
        self::assertSame('150.00', $restaurant->getMinOrderAmount());
        self::assertSame(['account_name' => 'Renamed Kitchen', 'account_number' => '09171112222'], $restaurant->getPayoutAccountDetails());
        self::assertSame('+639171112222', $restaurant->getUser()->getPhone());

        $crawler = $this->page('/restaurant/profile');
        $this->client->submit($crawler->selectButton('Save profile')->form(['restaurant_profile_form[defaultPrepTimeMinutes]' => '500']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'between 1 and 240 minutes');
    }

    public function testAnotherAccountsPhoneIsRefused(): void
    {
        $crawler = $this->page('/restaurant/profile');
        $this->client->submit($crawler->selectButton('Save profile')->form([
            'restaurant_profile_form[phone]' => $this->otherRestaurant->getUser()->getPhone(),
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This phone number is already registered.');
    }

    public function testOperatingStatusSwitchesBetweenAllStates(): void
    {
        foreach (Restaurant::OPERATING_STATUSES as $status) {
            $crawler = $this->page('/restaurant');
            $this->client->submit($crawler->filter('#operating-status')->form(['status' => $status]));
            self::assertResponseRedirects('/restaurant');
            self::assertSame($status, $this->fresh(Restaurant::class, (string) $this->restaurant->getId())->getOperatingStatus());
        }
    }

    public function testWeeklyHoursAreSavedReplacedAndValidated(): void
    {
        $crawler = $this->page('/restaurant/hours');
        $this->client->submit($crawler->selectButton('Save hours')->form([
            'operating_hours_form[opens_1]' => '10:00', 'operating_hours_form[closes_1]' => '21:00',
            'operating_hours_form[opens_5]' => '18:00', 'operating_hours_form[closes_5]' => '02:00',
        ]));
        self::assertResponseRedirects('/restaurant/hours');
        self::assertSame([1 => '10:00-21:00', 5 => '18:00-02:00'], $this->hours());

        $crawler = $this->page('/restaurant/hours');
        $this->client->submit($crawler->selectButton('Save hours')->form([
            'operating_hours_form[opens_1]' => '', 'operating_hours_form[closes_1]' => '',
            'operating_hours_form[opens_2]' => '09:00', 'operating_hours_form[closes_2]' => '17:00',
        ]));
        self::assertSame([2 => '09:00-17:00', 5 => '18:00-02:00'], $this->hours(), 'Monday removed, Tuesday added, Friday kept.');

        $crawler = $this->page('/restaurant/hours');
        $this->client->submit($crawler->selectButton('Save hours')->form(['operating_hours_form[opens_3]' => '09:00']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Give both times for Wednesday');
    }

    public function testDocumentsAreUploadedPrivatelyAndOnlyRejectedOnesReplaced(): void
    {
        $crawler = $this->page('/restaurant/documents');
        $form = $crawler->selectButton('Upload')->form(['document_upload_form[type]' => RestaurantDocument::TYPE_BUSINESS_PERMIT]);
        $form['document_upload_form[file]']->upload($this->pdf);
        $this->client->submit($form);
        self::assertResponseRedirects('/restaurant/documents');

        $document = $this->document(RestaurantDocument::TYPE_BUSINESS_PERMIT);
        self::assertSame(RestaurantDocument::STATUS_PENDING, $document->getStatus());
        self::assertFileExists(self::getContainer()->getParameter('app.private_upload_dir').'/'.$document->getFilePath());
        $this->client->request('GET', '/restaurant/documents/'.$document->getId().'/file');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $crawler = $this->page('/restaurant/documents');
        self::assertCount(0, $crawler->filter('#document_upload_form_type option[value="business_permit"]'), 'Pending documents cannot be replaced.');

        $this->document(RestaurantDocument::TYPE_BUSINESS_PERMIT)->setStatus(RestaurantDocument::STATUS_REJECTED)->setRejectionReason('Blurry');
        $this->entityManager->flush();
        $crawler = $this->page('/restaurant/documents');
        self::assertSelectorTextContains('#document-business_permit', 'Blurry');
        $form = $crawler->selectButton('Upload')->form(['document_upload_form[type]' => RestaurantDocument::TYPE_BUSINESS_PERMIT]);
        $form['document_upload_form[file]']->upload($this->pdf);
        $this->client->submit($form);
        $replaced = $this->document(RestaurantDocument::TYPE_BUSINESS_PERMIT);
        self::assertSame((string) $document->getId(), (string) $replaced->getId());
        self::assertSame(RestaurantDocument::STATUS_PENDING, $replaced->getStatus());
        self::assertNull($replaced->getRejectionReason());
    }

    public function testDocumentsAreOpenToPendingRestaurantsButPrivateToOthers(): void
    {
        $now = new \DateTimeImmutable();
        $foreign = (new RestaurantDocument())->setRestaurant($this->otherRestaurant)->setType(RestaurantDocument::TYPE_OWNER_ID)
            ->setFilePath('restaurants/documents/foreign.pdf')->setStatus(RestaurantDocument::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($foreign);
        $this->entityManager->flush();
        $this->fresh(Restaurant::class, (string) $this->restaurant->getId())->setApprovalStatus(Restaurant::APPROVAL_PENDING);
        $this->entityManager->flush();

        $this->page('/restaurant/documents');
        $this->client->request('GET', '/restaurant/documents/'.$foreign->getId().'/file');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/restaurant/profile');
        self::assertResponseStatusCodeSame(403, 'The profile still needs approval.');
    }

    private function page(string $url): Crawler
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array<int, string> day => "HH:MM-HH:MM" */
    private function hours(): array
    {
        $this->entityManager->clear();
        $hours = [];
        foreach ($this->entityManager->getRepository(RestaurantOperatingHour::class)->findBy(['restaurant' => $this->restaurant->getId()], ['dayOfWeek' => 'ASC']) as $hour) {
            $hours[$hour->getDayOfWeek()] = $hour->getOpensAt()->format('H:i').'-'.$hour->getClosesAt()->format('H:i');
        }

        return $hours;
    }

    private function document(string $type): RestaurantDocument
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(RestaurantDocument::class)->findOneBy(['restaurant' => $this->restaurant->getId(), 'type' => $type]);
    }
}
