<?php

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\Rider;
use App\Entity\RiderDocument;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;

class RegistrationControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';

    private KernelBrowser $client;
    private string $uploadDir;
    private string $pdf;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->uploadDir = self::getContainer()->getParameter('app.private_upload_dir');
        $this->pdf = sys_get_temp_dir().'/foodjett-registration-test.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n%test document\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->uploadDir, $this->pdf]);
        parent::tearDown();
    }

    public function testCustomerRegistersAndIsSignedIn(): void
    {
        $form = $this->form('/register', 'registration_form');
        $this->fillAccount($form, 'registration_form', 'Register.Customer@Foodjett.test', '+639180000001');
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Signed in as Test Person (customer)');

        $user = $this->user('register.customer@foodjett.test');
        self::assertSame(User::ACCOUNT_CUSTOMER, $user->getRole());
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertInstanceOf(Customer::class, $user->getCustomer());
    }

    public function testRestaurantApplicationIsPendingClosedWithPrivateDocuments(): void
    {
        $form = $this->form('/register/restaurant', 'restaurant_registration_form');
        $this->fillAccount($form, 'restaurant_registration_form[user]', 'register-restaurant@foodjett.test', '+639180000002');
        $form['restaurant_registration_form[name]'] = 'Test Kitchen';
        $form['restaurant_registration_form[cuisineType]'] = 'Filipino';
        $form['restaurant_registration_form[address]'] = '1 Test Street, Manila';
        $form['restaurant_registration_form[latitude]'] = '14.5995';
        $form['restaurant_registration_form[longitude]'] = '120.9842';
        foreach (RestaurantDocument::TYPES as $type) {
            $form['restaurant_registration_form[document_'.$type.']']->upload($this->pdf);
        }
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $restaurant = $this->user('register-restaurant@foodjett.test')->getRestaurant();
        self::assertInstanceOf(Restaurant::class, $restaurant);
        self::assertSame(Restaurant::APPROVAL_PENDING, $restaurant->getApprovalStatus());
        self::assertSame(Restaurant::OPERATING_CLOSED, $restaurant->getOperatingStatus());
        self::assertStringStartsWith('test-kitchen', $restaurant->getSlug());
        self::assertCount(count(RestaurantDocument::TYPES), $restaurant->getDocuments());
        foreach ($restaurant->getDocuments() as $document) {
            self::assertFileExists($this->uploadDir.'/'.$document->getFilePath());
            self::assertStringStartsNotWith('uploads/', $document->getFilePath());
        }
    }

    public function testRiderApplicationIsPendingOffline(): void
    {
        $form = $this->riderForm('register-rider@foodjett.test', '+639180000003', Rider::VEHICLE_MOTORCYCLE, 'ABC 1234');
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $rider = $this->user('register-rider@foodjett.test')->getRider();
        self::assertInstanceOf(Rider::class, $rider);
        self::assertSame(Rider::APPROVAL_PENDING, $rider->getApprovalStatus());
        self::assertSame(Rider::AVAILABILITY_OFFLINE, $rider->getAvailabilityStatus());
        self::assertSame('ABC 1234', $rider->getPlateNumber());
        self::assertCount(count(RiderDocument::TYPES), $rider->getDocuments());
    }

    public function testBicycleDoesNotNeedAPlateNumber(): void
    {
        $this->client->submit($this->riderForm('register-bicycle@foodjett.test', '+639180000004', Rider::VEHICLE_BICYCLE, ''));

        self::assertResponseRedirects('/');
        self::assertNull($this->user('register-bicycle@foodjett.test')->getRider()->getPlateNumber());
    }

    public function testMotorcycleNeedsAPlateNumber(): void
    {
        $this->client->submit($this->riderForm('register-noplate@foodjett.test', '+639180000005', Rider::VEHICLE_MOTORCYCLE, ''));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'A plate number is required for this vehicle type.');
        self::assertNull($this->findUser('register-noplate@foodjett.test'));
    }

    public function testPasswordConfirmationAndUniquenessAreChecked(): void
    {
        $form = $this->form('/register', 'registration_form');
        $this->fillAccount($form, 'registration_form', 'register-first@foodjett.test', '+639180000006');
        $this->client->submit($form);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/logout');

        $form = $this->form('/register', 'registration_form');
        $this->fillAccount($form, 'registration_form', 'REGISTER-FIRST@foodjett.test', '+639180000006');
        $form['registration_form[plainPassword][second]'] = 'something-else';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $body = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('This email address is already registered.', $body);
        self::assertStringContainsString('This phone number is already registered.', $body);
        self::assertStringContainsString('The password confirmation does not match.', $body);
    }

    public function testApplicationNeedsEveryDocumentBeforeAnAccountIsCreated(): void
    {
        $form = $this->riderForm('register-missing-doc@foodjett.test', '+639180000007', Rider::VEHICLE_BICYCLE, '');
        $form['rider_registration_form[document_'.RiderDocument::TYPE_POLICE_CLEARANCE.']']->upload('');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This document is required.');
        self::assertNull($this->findUser('register-missing-doc@foodjett.test'));
        self::assertDirectoryDoesNotExist($this->uploadDir.'/riders');
    }

    private function riderForm(string $email, string $phone, string $vehicle, string $plate): Form
    {
        $form = $this->form('/register/rider', 'rider_registration_form');
        $this->fillAccount($form, 'rider_registration_form[user]', $email, $phone);
        $form['rider_registration_form[vehicleType]'] = $vehicle;
        $form['rider_registration_form[plateNumber]'] = $plate;
        foreach (RiderDocument::TYPES as $type) {
            $form['rider_registration_form[document_'.$type.']']->upload($this->pdf);
        }

        return $form;
    }

    private function form(string $url, string $name): Form
    {
        return $this->client->request('GET', $url)->filter('form[name="'.$name.'"]')->form();
    }

    private function fillAccount(Form $form, string $prefix, string $email, string $phone): void
    {
        $form[$prefix.'[name]'] = 'Test Person';
        $form[$prefix.'[email]'] = $email;
        $form[$prefix.'[phone]'] = $phone;
        $form[$prefix.'[plainPassword][first]'] = self::PASSWORD;
        $form[$prefix.'[plainPassword][second]'] = self::PASSWORD;
        $form[$prefix.'[agreeTerms]']->tick();
    }

    private function user(string $email): User
    {
        $user = $this->findUser($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function findUser(string $email): ?User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
