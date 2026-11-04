<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Admin;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class MercureAuthorizationControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $customerUser;
    private User $otherCustomerUser;
    private User $restaurantUser;
    private User $riderUser;
    private User $adminUser;
    private Restaurant $restaurant;
    private Order $activeOrder;
    private Order $terminalOrder;
    private Conversation $conversation;
    private string $marker;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'MERCURE-'.bin2hex(random_bytes(5));
        $now = new \DateTimeImmutable();

        $this->customerUser = $this->user('customer', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($this->customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Home')->setAddressLine('Test address')
            ->setLatitude('14.6000000')->setLongitude('120.9800000')->setIsDefault(true)->setCreatedAt($now)->setUpdatedAt($now);

        $this->otherCustomerUser = $this->user('other-customer', User::ACCOUNT_CUSTOMER, $now);
        $otherCustomer = (new Customer())->setUser($this->otherCustomerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $otherAddress = (new CustomerAddress())->setCustomer($otherCustomer)->setLabel('Other')->setAddressLine('Other address')
            ->setLatitude('14.7000000')->setLongitude('121.0000000')->setIsDefault(true)->setCreatedAt($now)->setUpdatedAt($now);

        $this->restaurantUser = $this->user('restaurant', User::ACCOUNT_RESTAURANT, $now);
        $this->restaurant = (new Restaurant())->setUser($this->restaurantUser)->setName($this->marker.' Kitchen')
            ->setSlug(strtolower($this->marker).'-kitchen')->setAddress('Pickup address')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);

        $this->riderUser = $this->user('rider', User::ACCOUNT_RIDER, $now);
        $rider = (new Rider())->setUser($this->riderUser)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setPlateNumber('MC-100')
            ->setApprovalStatus(Rider::APPROVAL_APPROVED)->setAvailabilityStatus(Rider::AVAILABILITY_AVAILABLE)
            ->setCreatedAt($now)->setUpdatedAt($now);

        $this->adminUser = $this->user('admin', User::ACCOUNT_ADMIN, $now);
        $admin = (new Admin())->setUser($this->adminUser)->setCreatedAt($now)->setUpdatedAt($now);

        $this->activeOrder = $this->order($customer, $address, $rider, Order::STATUS_RIDER_ASSIGNED, 'ACTIVE', $now);
        $this->terminalOrder = $this->order($customer, $address, $rider, Order::STATUS_DELIVERED, 'DONE', $now);
        $otherOrder = $this->order($otherCustomer, $otherAddress, null, Order::STATUS_PLACED, 'OTHER', $now);
        $this->conversation = (new Conversation())->setOrder($this->activeOrder)->setType(Conversation::TYPE_CUSTOMER_RIDER)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $otherConversation = (new Conversation())->setOrder($otherOrder)->setType(Conversation::TYPE_CUSTOMER_RESTAURANT)
            ->setCreatedAt($now)->setUpdatedAt($now);

        foreach ([
            $this->customerUser, $customer, $address, $this->otherCustomerUser, $otherCustomer, $otherAddress,
            $this->restaurantUser, $this->restaurant, $this->riderUser, $rider, $this->adminUser, $admin,
            $this->activeOrder, $this->terminalOrder, $otherOrder, $this->conversation, $otherConversation,
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testCustomerReceivesOnlyOwnedOrderConversationAndNotificationTopics(): void
    {
        $payload = $this->authorize($this->customerUser);

        self::assertSame('http://localhost:3000/.well-known/mercure', $payload['hub_url']);
        self::assertSame('match', $payload['subscription_parameter']);
        self::assertContains('orders/'.$this->activeOrder->getId().'/status', $payload['topics']);
        self::assertContains('orders/'.$this->terminalOrder->getId().'/status', $payload['topics']);
        self::assertContains('conversations/'.$this->conversation->getId(), $payload['topics']);
        self::assertContains('users/'.$this->customerUser->getId().'/notifications', $payload['topics']);
        self::assertNotContains('orders/pool', $payload['topics']);

        $cookie = $this->authorizationCookie();
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertSame('user/'.$this->customerUser->getId(), $this->decodeJwt($cookie->getValue())['sub']);
        $responseTopics = $payload['topics'];
        $jwtTopics = $this->jwtTopics($cookie->getValue());
        sort($responseTopics);
        sort($jwtTopics);
        self::assertSame($responseTopics, $jwtTopics);
    }

    public function testApprovedRiderReceivesPoolActiveOrderAndAssignedConversationButNotTerminalOrder(): void
    {
        $payload = $this->authorize($this->riderUser);

        self::assertContains('orders/pool', $payload['topics']);
        self::assertContains('orders/'.$this->activeOrder->getId().'/status', $payload['topics']);
        self::assertContains('conversations/'.$this->conversation->getId(), $payload['topics']);
        self::assertContains('users/'.$this->riderUser->getId().'/notifications', $payload['topics']);
        self::assertNotContains('orders/'.$this->terminalOrder->getId().'/status', $payload['topics']);
    }

    public function testRestaurantReceivesOnlyItsOrderAlertTopic(): void
    {
        $payload = $this->authorize($this->restaurantUser);

        self::assertSame(['restaurant/'.$this->restaurant->getId().'/orders'], $payload['topics']);
    }

    public function testAdminReceivesCurrentOperationalTopics(): void
    {
        $payload = $this->authorize($this->adminUser);

        self::assertContains('orders/pool', $payload['topics']);
        self::assertContains('restaurant/'.$this->restaurant->getId().'/orders', $payload['topics']);
        self::assertContains('orders/'.$this->activeOrder->getId().'/status', $payload['topics']);
        self::assertContains('conversations/'.$this->conversation->getId(), $payload['topics']);
    }

    /** @return array<string, mixed> */
    private function authorize(User $user): array
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => $user->getEmail(), 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        $login = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/api/mercure-auth', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['token']]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function authorizationCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('mercure_access_token' === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('Mercure authorization cookie was not set.');
    }

    /** @return list<string> */
    private function jwtTopics(string $jwt): array
    {
        $payload = $this->decodeJwt($jwt);
        $topics = [];
        foreach ($payload['authorization_details'] ?? [] as $grant) {
            if (!in_array('subscribe', $grant['actions'] ?? [], true)) {
                continue;
            }
            foreach ($grant['topics'] ?? [] as $topic) {
                $topics[] = (string) ($topic['match'] ?? '');
            }
        }

        return array_values(array_filter($topics));
    }

    /** @return array<string, mixed> */
    private function decodeJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        $encoded = strtr($parts[1], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);

        return json_decode((string) base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(string $label, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($this->marker.' '.$label)->setEmail(strtolower($this->marker)."-$label@foodjett.test")
            ->setPhone('+639'.random_int(100000000, 999999999))->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function order(
        Customer $customer,
        CustomerAddress $address,
        ?Rider $rider,
        string $status,
        string $suffix,
        \DateTimeImmutable $now,
    ): Order {
        return (new Order())->setOrderNumber($this->marker.'-'.$suffix)->setCustomer($customer)->setRestaurant($this->restaurant)
            ->setCustomerAddress($address)->setRider($rider)->setStatus($status)->setSubtotal('100.00')->setDeliveryFee('35.00')
            ->setServiceFee('0.00')->setDiscountAmount('0.00')->setTipAmount('0.00')->setTotalAmount('135.00')
            ->setCommissionAmount('15.00')->setPaymentMethod(Payment::METHOD_COD)->setPlacedAt($now)->setCreatedAt($now)->setUpdatedAt($now);
    }
}
