<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\Rider;
use App\Entity\RiderDocument;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Restaurant and rider applications: queues, details, decisions and document review. */
class AdminApprovalPagesTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const ADMIN_EMAIL = 'approval-admin@foodjett.test';
    private const RESTAURANT_EMAIL = 'approval-restaurant@foodjett.test';
    private const RIDER_EMAIL = 'approval-rider@foodjett.test';

    private KernelBrowser $client;
    private User $adminUser;
    private string $restaurantId;
    private string $restaurantDocumentId;
    private string $riderId;
    private string $riderDocumentId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->createFixture();
        $this->client->loginUser($this->adminUser, 'main');
    }

    public function testPendingQueuesListApplicationsAndSupportSearch(): void
    {
        $this->page('/admin/restaurant?approval=pending');
        self::assertSelectorTextContains('#restaurant-'.$this->restaurantId, 'Approval Restaurant');
        self::assertSelectorTextContains('#restaurant-'.$this->restaurantId, 'pending');

        $crawler = $this->page('/admin/rider?approval=pending&search=Approval%20Rider');
        self::assertSelectorTextContains('#rider-'.$this->riderId, 'Approval Rider');
        self::assertCount(1, $crawler->filter('tbody tr[id^="rider-"]'));

        $crawler = $this->page('/admin/rider?approval=approved&search=Approval%20Rider');
        self::assertCount(0, $crawler->filter('tbody tr[id^="rider-"]'));
    }

    public function testDetailsShowProfilesAndDocuments(): void
    {
        $this->page('/admin/restaurant/'.$this->restaurantId);
        self::assertSelectorTextContains('h1', 'Approval Restaurant');
        self::assertSelectorTextContains('table', 'Restaurant Owner');
        self::assertSelectorTextContains('#document-'.$this->restaurantDocumentId, 'Business permit');

        $this->page('/admin/rider/'.$this->riderId);
        self::assertSelectorTextContains('h1', 'Approval Rider');
        self::assertSelectorTextContains('table', 'motorcycle');
        self::assertSelectorTextContains('#document-'.$this->riderDocumentId, 'Drivers license');
    }

    public function testRestaurantApprovalRejectionAndSuspensionAreAudited(): void
    {
        $url = '/admin/restaurant/'.$this->restaurantId;
        $this->client->submit($this->page($url)->selectButton('Reject application')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="reject"]', 'Give a reason.');

        $this->client->submit($this->page($url)->selectButton('Reject application')->form(['reject[reason]' => 'Permit is unreadable.']));
        self::assertResponseRedirects($url);
        $this->page($url);
        self::assertSelectorTextContains('#approval', 'rejected');
        self::assertSelectorTextContains('#approval', 'Permit is unreadable.');

        $this->client->submit($this->page($url)->selectButton('Approve')->form());
        self::assertResponseRedirects($url);
        $restaurant = $this->fresh(Restaurant::class, $this->restaurantId);
        self::assertSame(Restaurant::APPROVAL_APPROVED, $restaurant->getApprovalStatus());
        self::assertNull($restaurant->getRejectionReason());

        $this->client->submit($this->page($url)->selectButton('Suspend account')->form());
        self::assertResponseRedirects($url);
        self::assertSame(User::STATUS_SUSPENDED, $this->fresh(Restaurant::class, $this->restaurantId)->getUser()->getStatus());

        $this->client->submit($this->page($url)->selectButton('Reactivate account')->form());
        self::assertResponseRedirects($url);
        self::assertSame(User::STATUS_ACTIVE, $this->fresh(Restaurant::class, $this->restaurantId)->getUser()->getStatus());

        $actions = $this->auditActions($this->restaurantId);
        self::assertContains('restaurant.rejected', $actions);
        self::assertContains('restaurant.approved', $actions);
        self::assertContains('restaurant.suspended', $actions);
        self::assertContains('restaurant.reactivated', $actions);
    }

    public function testRiderRejectionAndSuspensionForceOfflineAndAreAudited(): void
    {
        $url = '/admin/rider/'.$this->riderId;
        $this->client->submit($this->page($url)->selectButton('Reject application')->form(['reject[reason]' => 'ID mismatch.']));
        self::assertResponseRedirects($url);
        $rider = $this->fresh(Rider::class, $this->riderId);
        self::assertSame(Rider::APPROVAL_REJECTED, $rider->getApprovalStatus());
        self::assertSame(Rider::AVAILABILITY_OFFLINE, $rider->getAvailabilityStatus());

        $this->client->submit($this->page($url)->selectButton('Approve')->form());
        self::assertResponseRedirects($url);
        $rider = $this->fresh(Rider::class, $this->riderId);
        $rider->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE);
        $this->entityManager()->flush();

        $this->client->submit($this->page($url)->selectButton('Suspend account')->form());
        self::assertResponseRedirects($url);
        $rider = $this->fresh(Rider::class, $this->riderId);
        self::assertSame(User::STATUS_SUSPENDED, $rider->getUser()->getStatus());
        self::assertSame(Rider::AVAILABILITY_OFFLINE, $rider->getAvailabilityStatus());

        $actions = $this->auditActions($this->riderId);
        self::assertContains('rider.rejected', $actions);
        self::assertContains('rider.approved', $actions);
        self::assertContains('rider.suspended', $actions);
    }

    public function testAdminCanCorrectRiderVehicleAndCashLimit(): void
    {
        $url = '/admin/rider/'.$this->riderId;
        $this->client->submit($this->page($url.'/edit')->selectButton('Update')->form([
            'rider[plateNumber]' => ' NEW-123 ',
            'rider[cashRemitLimit]' => '2500',
        ]));
        self::assertResponseRedirects($url);
        $rider = $this->fresh(Rider::class, $this->riderId);
        self::assertSame('NEW-123', $rider->getPlateNumber());
        self::assertSame('2500.00', $rider->getCashRemitLimit());
        self::assertContains('rider.updated', $this->auditActions($this->riderId));
    }

    public function testDocumentReviewIsIndependentFromOverallApproval(): void
    {
        $this->client->submit($this->page('/admin/restaurant/'.$this->restaurantId)->filter('#document-'.$this->restaurantDocumentId)->selectButton('Verify')->form());
        self::assertResponseRedirects('/admin/restaurant/'.$this->restaurantId);

        $form = $this->page('/admin/rider/'.$this->riderId)->filter('#document-'.$this->riderDocumentId)->selectButton('Reject document')->form([
            'document_'.$this->riderDocumentId.'[reason]' => 'Expired license.',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/rider/'.$this->riderId);

        self::assertSame(RestaurantDocument::STATUS_VERIFIED, $this->fresh(RestaurantDocument::class, $this->restaurantDocumentId)->getStatus());
        $document = $this->fresh(RiderDocument::class, $this->riderDocumentId);
        self::assertSame(RiderDocument::STATUS_REJECTED, $document->getStatus());
        self::assertSame('Expired license.', $document->getRejectionReason());
        self::assertSame(Restaurant::APPROVAL_PENDING, $this->fresh(Restaurant::class, $this->restaurantId)->getApprovalStatus());
        self::assertSame(Rider::APPROVAL_PENDING, $this->fresh(Rider::class, $this->riderId)->getApprovalStatus());
        self::assertContains('restaurant_document.verified', $this->auditActions($this->restaurantDocumentId));
    }

    public function testNonAdminsAreRefused(): void
    {
        $this->client->loginUser($this->fresh(Restaurant::class, $this->restaurantId)->getUser(), 'main');
        $this->client->request('GET', '/admin/restaurant');
        self::assertResponseStatusCodeSame(403);
    }

    private function createFixture(): void
    {
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $this->adminUser = $this->user('Approval Admin', self::ADMIN_EMAIL, '+639190100001', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($this->adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Restaurant Owner', self::RESTAURANT_EMAIL, '+639190100002', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)->setName('Approval Restaurant')->setSlug('approval-restaurant')
            ->setCuisineType('Filipino')->setAddress('Approval Street')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_PENDING)->setOperatingStatus(Restaurant::OPERATING_CLOSED)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantDocument = (new RestaurantDocument())->setRestaurant($restaurant)->setType(RestaurantDocument::TYPE_BUSINESS_PERMIT)
            ->setFilePath('documents/test-permit.pdf')->setStatus(RestaurantDocument::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $restaurant->addDocument($restaurantDocument);
        $riderUser = $this->user('Approval Rider', self::RIDER_EMAIL, '+639190100003', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setPlateNumber('TEST-APPROVAL')
            ->setApprovalStatus(Rider::APPROVAL_PENDING)->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $riderDocument = (new RiderDocument())->setRider($rider)->setType(RiderDocument::TYPE_DRIVERS_LICENSE)
            ->setFilePath('documents/test-license.pdf')->setStatus(RiderDocument::STATUS_PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $rider->addDocument($riderDocument);
        foreach ([$this->adminUser, $admin, $restaurantUser, $restaurant, $restaurantDocument, $riderUser, $rider, $riderDocument] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->restaurantId = (string) $restaurant->getId();
        $this->restaurantDocumentId = (string) $restaurantDocument->getId();
        $this->riderId = (string) $rider->getId();
        $this->riderDocumentId = (string) $riderDocument->getId();
    }

    private function user(string $name, string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))->setRole($role)->setStatus(User::STATUS_ACTIVE)
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
    private function fresh(string $class, string $id): object
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
