<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class RestaurantApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'TestPassword123!';

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected Restaurant $restaurant;
    protected Restaurant $otherRestaurant;
    protected Customer $customer;
    protected CustomerAddress $address;
    protected User $restaurantUser;
    protected string $marker;

    /** @var list<string> */
    protected array $uploadedPaths = [];

    private int $orderSequence = 0;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'RAPI-'.bin2hex(random_bytes(5));
        $now = new \DateTimeImmutable();

        $this->restaurantUser = $this->user('restaurant-a', UserRole::RESTAURANT, $now);
        $otherUser = $this->user('restaurant-b', UserRole::RESTAURANT, $now);
        $this->restaurant = $this->restaurantProfile($this->restaurantUser, 'Restaurant A', 'a', $now);
        $this->otherRestaurant = $this->restaurantProfile($otherUser, 'Restaurant B', 'b', $now);
        $customerUser = $this->user('customer', UserRole::CUSTOMER, $now);
        $this->customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $this->address = (new CustomerAddress())->setCustomer($this->customer)->setLabel($this->marker)
            ->setAddressLine('123 Test Street')->setLatitude('14.6091000')->setLongitude('121.0223000')
            ->setCreatedAt($now)->setUpdatedAt($now);

        $this->persist($this->restaurantUser, $this->restaurant, $otherUser, $this->otherRestaurant, $customerUser, $this->customer, $this->address);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->uploadedPaths as $relativePath) {
            $path = dirname(__DIR__, 3).'/public/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    protected function auth(): array
    {
        if (null === $this->token) {
            $this->client->jsonRequest('POST', '/api/login', [
                'login' => $this->restaurantUser->getEmail(),
                'password' => self::PASSWORD,
            ]);
            self::assertResponseIsSuccessful();
            $this->token = (string) ($this->payload()['token'] ?? '');
        }

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
    }

    /**
     * Requests can reset Doctrine's manager even when the test kernel is retained.
     * Always re-read mutated state instead of asserting against a stale fixture object.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function fresh(string $class, string $id): object
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->clear();
        $entity = $this->entityManager->find($class, $id);
        self::assertInstanceOf($class, $entity);

        return $entity;
    }

    protected function createOrder(
        Restaurant $restaurant,
        OrderStatus $status = OrderStatus::PLACED,
        string $subtotal = '100.00',
        string $total = '135.00',
        ?\DateTimeImmutable $placedAt = null,
        ?\DateTimeImmutable $deliveredAt = null,
        string $commission = '15.00',
    ): Order {
        $placedAt ??= new \DateTimeImmutable();
        $order = (new Order())->setOrderNumber($this->marker.'-'.(++$this->orderSequence))->setCustomer($this->customer)
            ->setRestaurant($restaurant)->setCustomerAddress($this->address)->setStatus($status)->setSubtotal($subtotal)
            ->setDeliveryFee('35.00')->setServiceFee('0.00')->setDiscountAmount('0.00')->setTipAmount('0.00')
            ->setTotalAmount($total)->setCommissionAmount($commission)->setPaymentMethod(PaymentMethod::COD)
            ->setPlacedAt($placedAt)->setDeliveredAt($deliveredAt)->setCreatedAt($placedAt)->setUpdatedAt($placedAt);
        $this->persist($order);

        return $order;
    }

    protected function createCategory(Restaurant $restaurant, string $name = 'Category'): MenuCategory
    {
        $now = new \DateTimeImmutable();
        $category = (new MenuCategory())->setRestaurant($restaurant)->setName($this->marker.' '.$name)
            ->setSortOrder(1)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($category);

        return $category;
    }

    protected function createItem(MenuCategory $category, string $name = 'Item'): MenuItem
    {
        $now = new \DateTimeImmutable();
        $item = (new MenuItem())->setMenuCategory($category)->setName($this->marker.' '.$name)->setBasePrice('99.00')
            ->setIsAvailable(true)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($item);

        return $item;
    }

    private function user(string $label, UserRole $role, \DateTimeImmutable $now): User
    {
        $suffix = bin2hex(random_bytes(3));

        return (new User())->setName($this->marker.' '.$label)->setEmail(strtolower($this->marker)."-$label-$suffix@foodjett.test")
            ->setPhone('+639'.random_int(100000000, 999999999))->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)->setStatus(UserStatus::ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function restaurantProfile(User $user, string $name, string $suffix, \DateTimeImmutable $now): Restaurant
    {
        return (new Restaurant())->setUser($user)->setName($this->marker.' '.$name)->setSlug(strtolower($this->marker)."-$suffix")
            ->setAddress('Restaurant Test Address')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setApprovalStatus(ApprovalStatus::APPROVED)->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }
}
