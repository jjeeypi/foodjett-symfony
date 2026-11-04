<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AuditLog;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\RestaurantReview;
use App\Entity\Rider;
use App\Entity\RiderReview;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/** Review moderation, the audit log and administrator accounts. */
class AdminModerationPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $adminUser;
    private string $adminId;
    private string $restaurantReviewId;
    private string $riderReviewId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->createFixture();
        $this->client->loginUser($this->adminUser, 'main');
    }

    public function testReviewsAreListedByKindAndAbusiveOnesRemoved(): void
    {
        $crawler = $this->page('/admin/review?max_rating=1');
        self::assertSelectorTextContains('#review-'.$this->restaurantReviewId, 'Moderation Restaurant');
        self::assertSelectorTextContains('#review-'.$this->restaurantReviewId, 'Terrible, and rude words.');

        $this->page('/admin/review?type=rider');
        self::assertSelectorTextContains('#review-'.$this->riderReviewId, 'Moderation Rider');

        $this->client->submit($crawler->filter('#review-'.$this->restaurantReviewId)->selectButton('Remove review')->form());
        self::assertResponseRedirects('/admin/review?type=restaurant');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(RestaurantReview::class, $this->restaurantReviewId));
        self::assertNotNull($this->entityManager()->find(RiderReview::class, $this->riderReviewId));

        $this->client->request('POST', '/admin/review/rider/'.$this->riderReviewId, ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAuditLogShowsDecisionsAndFiltersByAction(): void
    {
        $crawler = $this->page('/admin/review');
        $this->client->submit($crawler->filter('#review-'.$this->restaurantReviewId)->selectButton('Remove review')->form());

        $crawler = $this->page('/admin/audit-log?action=review.deleted');
        $rows = $crawler->filter('tbody tr[id^="log-"]');
        self::assertGreaterThanOrEqual(1, $rows->count());
        self::assertSame('review.deleted', $rows->first()->filter('.action')->text());
        self::assertStringContainsString('Moderation Admin', $rows->first()->text());
        self::assertStringContainsString('restaurant review #'.$this->restaurantReviewId, $rows->first()->text());

        $crawler = $this->page('/admin/audit-log?action=review.deleted&from=2020-01-01&to=2020-01-02');
        self::assertCount(0, $crawler->filter('tbody tr[id^="log-"]'));
    }

    public function testAdminsAreAddedSuspendedAndCannotSuspendThemselves(): void
    {
        $crawler = $this->page('/admin/account');
        self::assertSelectorNotExists('#admin-'.$this->adminId.' form');

        $this->client->submit($crawler->selectButton('Add administrator')->form([
            'admin_account_form[name]' => 'Created Admin',
            'admin_account_form[email]' => 'Created-Admin@Foodjett.test',
            'admin_account_form[phone]' => '+639190600009',
            'admin_account_form[plainPassword][first]' => 'StartingPass123!',
            'admin_account_form[plainPassword][second]' => 'StartingPass123!',
        ]));
        self::assertResponseRedirects('/admin/account');
        $created = $this->entityManager()->getRepository(User::class)->findOneBy(['email' => 'created-admin@foodjett.test']) ?? self::fail('The admin was not created.');
        self::assertSame(User::ACCOUNT_ADMIN, $created->getRole());
        self::assertNotNull($created->getAdmin());
        self::assertTrue(self::getContainer()->get('security.user_password_hasher')->isPasswordValid($created, 'StartingPass123!'));
        $createdId = (string) $created->getAdmin()->getId();

        $this->client->submit($this->page('/admin/account')->selectButton('Add administrator')->form([
            'admin_account_form[name]' => 'Duplicate',
            'admin_account_form[email]' => 'created-admin@foodjett.test',
            'admin_account_form[phone]' => '+639190600010',
            'admin_account_form[plainPassword][first]' => 'StartingPass123!',
            'admin_account_form[plainPassword][second]' => 'StartingPass123!',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="admin_account_form"]', 'This email address is already registered.');

        $this->client->submit($this->page('/admin/account')->filter('#admin-'.$createdId)->selectButton('Suspend')->form());
        self::assertResponseRedirects('/admin/account');
        self::assertSame(User::STATUS_SUSPENDED, $this->find(Admin::class, $createdId)->getUser()->getStatus());

        // The page offers no button for yourself; a hand-made request with a valid token is still refused.
        $this->client->request('POST', '/admin/account/'.$this->adminId.'/status/suspended', ['_token' => $this->csrfToken('admin'.$this->adminId)]);
        self::assertResponseRedirects('/admin/account');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'You cannot suspend your own admin account.');
        self::assertSame(User::STATUS_ACTIVE, $this->find(Admin::class, $this->adminId)->getUser()->getStatus());

        self::assertSame(['admin.created', 'admin.suspended'], $this->auditActions($createdId));
    }

    /** Issues a CSRF token inside the browser's own session, as a page would. */
    private function csrfToken(string $id): string
    {
        $container = self::getContainer();
        $session = $container->get('session.factory')->createSession();
        $session->setId((string) $this->client->getCookieJar()->get($session->getName())?->getValue());
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        try {
            return $container->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $session->save();
            $container->get('request_stack')->pop();
        }
    }

    private function createFixture(): void
    {
        $entityManager = $this->entityManager();
        $now = new \DateTimeImmutable();
        $this->adminUser = $this->user('Moderation Admin', 'moderation-admin@foodjett.test', '+639190600001', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($this->adminUser)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('Moderation Customer', 'moderation-customer@foodjett.test', '+639190600002', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Moderation Address')->setAddressLine('Moderation Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantUser = $this->user('Moderation Owner', 'moderation-restaurant@foodjett.test', '+639190600003', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())->setUser($restaurantUser)->setName('Moderation Restaurant')->setSlug('moderation-restaurant')
            ->setAddress('Moderation Street')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $riderUser = $this->user('Moderation Rider', 'moderation-rider@foodjett.test', '+639190600004', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())->setUser($riderUser)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setApprovalStatus(Rider::APPROVAL_APPROVED)
            ->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)->setCreatedAt($now)->setUpdatedAt($now);
        $order = (new Order())->setOrderNumber('MODERATION-ORDER')->setCustomer($customer)->setRestaurant($restaurant)->setCustomerAddress($address)->setRider($rider)
            ->setStatus(Order::STATUS_DELIVERED)->setSubtotal('100.00')->setDeliveryFee('25.00')->setTotalAmount('125.00')
            ->setPaymentMethod(Payment::METHOD_COD)->setPlacedAt($now->modify('-1 hour'))->setDeliveredAt($now)->setCreatedAt($now)->setUpdatedAt($now);
        $restaurantReview = (new RestaurantReview())->setOrder($order)->setCustomer($customer)->setRestaurant($restaurant)
            ->setRating(1)->setComment('Terrible, and rude words.')->setCreatedAt($now)->setUpdatedAt($now);
        $riderReview = (new RiderReview())->setOrder($order)->setCustomer($customer)->setRider($rider)
            ->setRating(5)->setComment('Fast and friendly.')->setCreatedAt($now)->setUpdatedAt($now);
        foreach ([$this->adminUser, $admin, $customerUser, $customer, $address, $restaurantUser, $restaurant, $riderUser, $rider, $order, $restaurantReview, $riderReview] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->adminId = (string) $admin->getId();
        $this->restaurantReviewId = (string) $restaurantReview->getId();
        $this->riderReviewId = (string) $riderReview->getId();
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
