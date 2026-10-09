<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\RestaurantOperatingHour;
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

abstract class CustomerApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'TestPassword123!';

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected User $customerUser;
    protected User $otherCustomerUser;
    protected Customer $customer;
    protected Customer $otherCustomer;
    protected CustomerAddress $address;
    protected CustomerAddress $otherAddress;
    protected Restaurant $restaurant;
    protected Restaurant $otherRestaurant;
    protected string $marker;

    /** @var list<string> */
    protected array $uploadedPaths = [];

    private ?string $token = null;
    private int $orderSequence = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'CAPI-'.bin2hex(random_bytes(5));
        $now = new \DateTimeImmutable();

        $this->customerUser = $this->user('customer-a', UserRole::CUSTOMER, $now);
        $this->otherCustomerUser = $this->user('customer-b', UserRole::CUSTOMER, $now);
        $this->customer = (new Customer())->setUser($this->customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $this->otherCustomer = (new Customer())->setUser($this->otherCustomerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $this->address = $this->address($this->customer, 'Home', true, $now);
        $this->otherAddress = $this->address($this->otherCustomer, 'Other home', true, $now);

        $restaurantUser = $this->user('restaurant-a', UserRole::RESTAURANT, $now);
        $otherRestaurantUser = $this->user('restaurant-b', UserRole::RESTAURANT, $now);
        $this->restaurant = $this->restaurant($restaurantUser, 'Test Kitchen', 'Filipino', '14.5995000', '120.9842000', $now);
        $this->otherRestaurant = $this->restaurant($otherRestaurantUser, 'Second Kitchen', 'Japanese', '14.7000000', '121.1000000', $now);

        $this->persist(
            $this->customerUser, $this->otherCustomerUser, $this->customer, $this->otherCustomer, $this->address, $this->otherAddress,
            $restaurantUser, $otherRestaurantUser, $this->restaurant, $this->otherRestaurant,
        );
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
            $this->client->jsonRequest('POST', '/api/login', ['login' => $this->customerUser->getEmail(), 'password' => self::PASSWORD]);
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

    /** @template T of object
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

    protected function addCurrentHours(Restaurant $restaurant): RestaurantOperatingHour
    {
        $now = new \DateTimeImmutable();
        $hours = (new RestaurantOperatingHour())->setRestaurant($restaurant)->setDayOfWeek((int) $now->format('w'))
            ->setOpensAt(new \DateTimeImmutable('00:00:00'))->setClosesAt(new \DateTimeImmutable('23:59:59'))
            ->setCreatedAt($now)->setUpdatedAt($now);
        $restaurant->addOperatingHour($hours);
        $this->persist($hours);

        return $hours;
    }

    protected function createCategory(Restaurant $restaurant, string $name = 'Mains'): MenuCategory
    {
        $now = new \DateTimeImmutable();
        $category = (new MenuCategory())->setRestaurant($restaurant)->setName($this->marker.' '.$name)->setSortOrder(1)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($category);

        return $category;
    }

    protected function createItem(MenuCategory $category, string $name = 'Meal', string $price = '100.00', bool $available = true): MenuItem
    {
        $now = new \DateTimeImmutable();
        $item = (new MenuItem())->setMenuCategory($category)->setName($this->marker.' '.$name)->setDescription('Test food description')
            ->setBasePrice($price)->setIsAvailable($available)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($item);

        return $item;
    }

    protected function createOrder(
        Customer $customer,
        Restaurant $restaurant,
        CustomerAddress $address,
        OrderStatus $status = OrderStatus::PLACED,
        ?\DateTimeImmutable $placedAt = null,
        ?\DateTimeImmutable $deliveredAt = null,
    ): Order {
        $placedAt ??= new \DateTimeImmutable();
        $order = (new Order())->setOrderNumber($this->marker.'-'.(++$this->orderSequence))->setCustomer($customer)->setRestaurant($restaurant)
            ->setCustomerAddress($address)->setStatus($status)->setSubtotal('100.00')->setDeliveryFee('35.00')->setServiceFee('0.00')
            ->setDiscountAmount('0.00')->setTipAmount('0.00')->setTotalAmount('135.00')->setCommissionAmount('15.00')
            ->setPaymentMethod(PaymentMethod::COD)->setPlacedAt($placedAt)->setDeliveredAt($deliveredAt)->setCreatedAt($placedAt)->setUpdatedAt($placedAt);
        $this->persist($order);

        return $order;
    }

    private function user(string $label, UserRole $role, \DateTimeImmutable $now): User
    {
        $suffix = bin2hex(random_bytes(3));

        return (new User())->setName($this->marker.' '.$label)->setEmail(strtolower($this->marker)."-$label-$suffix@foodjett.test")
            ->setPhone('+639'.random_int(100000000, 999999999))->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)->setStatus(UserStatus::ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function address(Customer $customer, string $label, bool $default, \DateTimeImmutable $now): CustomerAddress
    {
        return (new CustomerAddress())->setCustomer($customer)->setLabel($this->marker.' '.$label)->setAddressLine('123 Test Street')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setIsDefault($default)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function restaurant(User $user, string $name, string $cuisine, string $latitude, string $longitude, \DateTimeImmutable $now): Restaurant
    {
        return (new Restaurant())->setUser($user)->setName($this->marker.' '.$name)->setSlug(strtolower($this->marker).'-'.strtolower(str_replace(' ', '-', $name)))
            ->setCuisineType($cuisine)->setAddress('Restaurant address')->setLatitude($latitude)->setLongitude($longitude)
            ->setApprovalStatus(ApprovalStatus::APPROVED)->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }
}
