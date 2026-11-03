<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\DocumentStatus;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RestaurantDocumentType;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RestaurantOwnershipBoundaryTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const RESTAURANT_A_EMAIL = 'restaurant-boundary-a@foodjett.test';
    private const RESTAURANT_B_EMAIL = 'restaurant-boundary-b@foodjett.test';
    private const CUSTOMER_EMAIL = 'restaurant-boundary-customer@foodjett.test';

    private KernelBrowser $client;
    private string $restaurantAId;
    private string $restaurantBId;
    private string $itemAId;
    private string $itemBId;
    private string $orderAId;
    private string $orderBId;
    private string $documentAId;
    private string $documentBId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->cleanup();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testListEndpointsOnlyReturnAuthenticatedRestaurantsData(): void
    {
        $auth = $this->auth($this->login());

        $this->client->request('GET', '/api/restaurant/menu-items', server: $auth);
        self::assertResponseIsSuccessful();
        $menuIds = array_column($this->payload()['data'] ?? [], 'id');
        self::assertContains($this->itemAId, $menuIds);
        self::assertNotContains($this->itemBId, $menuIds);

        $this->client->request('GET', '/api/restaurant/orders', server: $auth);
        self::assertResponseIsSuccessful();
        $orderIds = array_column($this->payload()['data'] ?? [], 'id');
        self::assertContains($this->orderAId, $orderIds);
        self::assertNotContains($this->orderBId, $orderIds);

        $this->client->request('GET', '/api/restaurant/documents', server: $auth);
        self::assertResponseIsSuccessful();
        $documentIds = array_column($this->payload()['documents'] ?? [], 'id');
        self::assertContains($this->documentAId, $documentIds);
        self::assertNotContains($this->documentBId, $documentIds);
    }

    public function testRestaurantCannotSeeOrMutateAnotherRestaurantsResources(): void
    {
        $auth = $this->auth($this->login());

        $this->client->jsonRequest('PATCH', '/api/restaurant/menu-items/'.$this->itemBId, ['name' => 'Stolen item'], server: $auth);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/restaurant/orders/'.$this->orderBId, server: $auth);
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', '/api/restaurant/orders/'.$this->orderBId.'/accept', ['estimated_prep_minutes' => 15], server: $auth);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/restaurant/documents/'.$this->documentBId, server: $auth);
        self::assertResponseStatusCodeSame(404);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame('Boundary B Item', $entityManager->find(MenuItem::class, $this->itemBId)?->getName());
        self::assertSame(OrderStatus::PLACED, $entityManager->find(Order::class, $this->orderBId)?->getStatus());
        self::assertNotNull($entityManager->find(RestaurantDocument::class, $this->documentBId));
    }

    private function seed(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $userA = $this->user(self::RESTAURANT_A_EMAIL, '+639188100001', UserRole::RESTAURANT, 'Boundary Restaurant A', $now);
        $userB = $this->user(self::RESTAURANT_B_EMAIL, '+639188100002', UserRole::RESTAURANT, 'Boundary Restaurant B', $now);
        $restaurantA = $this->restaurant($userA, 'Boundary Restaurant A', 'boundary-restaurant-a', $now);
        $restaurantB = $this->restaurant($userB, 'Boundary Restaurant B', 'boundary-restaurant-b', $now);
        $categoryA = (new MenuCategory())->setRestaurant($restaurantA)->setName('Boundary A Category')->setSortOrder(1)->setCreatedAt($now)->setUpdatedAt($now);
        $categoryB = (new MenuCategory())->setRestaurant($restaurantB)->setName('Boundary B Category')->setSortOrder(1)->setCreatedAt($now)->setUpdatedAt($now);
        $itemA = (new MenuItem())->setMenuCategory($categoryA)->setName('Boundary A Item')->setBasePrice('99.00')->setCreatedAt($now)->setUpdatedAt($now);
        $itemB = (new MenuItem())->setMenuCategory($categoryB)->setName('Boundary B Item')->setBasePrice('109.00')->setCreatedAt($now)->setUpdatedAt($now);
        $documentA = (new RestaurantDocument())->setRestaurant($restaurantA)->setType(RestaurantDocumentType::BUSINESS_PERMIT)
            ->setFilePath('tests/boundary-a.pdf')->setStatus(DocumentStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);
        $documentB = (new RestaurantDocument())->setRestaurant($restaurantB)->setType(RestaurantDocumentType::BUSINESS_PERMIT)
            ->setFilePath('tests/boundary-b.pdf')->setStatus(DocumentStatus::PENDING)->setCreatedAt($now)->setUpdatedAt($now);

        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639188100003', UserRole::CUSTOMER, 'Boundary Customer', $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Restaurant ownership boundary')
            ->setAddressLine('Boundary address')->setLatitude('14.6091000')->setLongitude('121.0223000')->setCreatedAt($now)->setUpdatedAt($now);
        $orderA = $this->order($customer, $address, $restaurantA, 'BOUNDARY-A-', $now);
        $orderB = $this->order($customer, $address, $restaurantB, 'BOUNDARY-B-', $now);

        foreach ([$userA, $restaurantA, $userB, $restaurantB, $categoryA, $categoryB, $itemA, $itemB, $documentA, $documentB, $customerUser, $customer, $address, $orderA, $orderB] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->restaurantAId = (string) $restaurantA->getId();
        $this->restaurantBId = (string) $restaurantB->getId();
        $this->itemAId = (string) $itemA->getId();
        $this->itemBId = (string) $itemB->getId();
        $this->orderAId = (string) $orderA->getId();
        $this->orderBId = (string) $orderB->getId();
        $this->documentAId = (string) $documentA->getId();
        $this->documentBId = (string) $documentB->getId();
    }

    private function user(string $email, string $phone, UserRole $role, string $name, \DateTimeImmutable $now): User
    {
        return (new User())->setName($name)->setEmail($email)->setPhone($phone)->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)->setStatus(UserStatus::ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function restaurant(User $user, string $name, string $slug, \DateTimeImmutable $now): Restaurant
    {
        return (new Restaurant())->setUser($user)->setName($name)->setSlug($slug)->setAddress('Boundary restaurant address')
            ->setLatitude('14.5995000')->setLongitude('120.9842000')->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function order(Customer $customer, CustomerAddress $address, Restaurant $restaurant, string $prefix, \DateTimeImmutable $now): Order
    {
        return (new Order())->setOrderNumber($prefix.bin2hex(random_bytes(4)))->setCustomer($customer)->setRestaurant($restaurant)
            ->setCustomerAddress($address)->setStatus(OrderStatus::PLACED)->setSubtotal('100.00')->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')->setPaymentMethod(PaymentMethod::COD)->setPlacedAt($now)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function login(): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::RESTAURANT_A_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        return $this->payload()['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("DELETE FROM orders WHERE order_number LIKE 'BOUNDARY-%'");
        $connection->executeStatement("DELETE FROM menu_items WHERE name IN ('Boundary A Item', 'Boundary B Item')");
        $connection->executeStatement("DELETE FROM menu_categories WHERE name IN ('Boundary A Category', 'Boundary B Category')");
        $connection->executeStatement("DELETE FROM restaurant_documents WHERE file_path LIKE 'tests/boundary-%'");
        $connection->executeStatement("DELETE FROM customer_addresses WHERE label = 'Restaurant ownership boundary'");
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))', [self::RESTAURANT_A_EMAIL, self::RESTAURANT_B_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?, ?)', [self::RESTAURANT_A_EMAIL, self::RESTAURANT_B_EMAIL, self::CUSTOMER_EMAIL]);
    }
}
