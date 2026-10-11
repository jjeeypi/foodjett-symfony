<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\DeliveryZone;
use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\RestaurantOperatingHour;
use App\Entity\User;
use App\Entity\Voucher;
use App\Enum\ApprovalStatus;
use App\Enum\PaymentStatus;
use App\Enum\RestaurantOperatingStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Enum\VoucherScope;
use App\Enum\VoucherType;
use App\Tests\Double\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckoutControllerTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const CUSTOMER_EMAIL = 'checkout-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'checkout-restaurant@foodjett.test';
    private const ZONE_NAME = 'Checkout Test Zone';
    private const VOUCHER_CODE = 'CHECKOUT10';

    private KernelBrowser $client;
    /** @var array<string, string> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(RecordingMercureHub::class)->reset();
        $this->cleanup();
        $this->fixture = $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCodCheckoutRecomputesAndSnapshotsTheOrderWithPendingPayment(): void
    {
        $payload = $this->checkout('cod', $this->uuid(), self::VOUCHER_CODE);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($payload['created'] ?? false);
        self::assertSame('260.00', $payload['order']['subtotal'] ?? null);
        self::assertSame('10.00', $payload['order']['discount_amount'] ?? null);
        self::assertSame('20.00', $payload['order']['tip_amount'] ?? null);
        self::assertSame('pending', $payload['order']['payment_status'] ?? null);

        $order = $this->order((string) $payload['order']['id']);
        self::assertCount(1, $order->getItems());
        self::assertSame('120.00', $order->getItems()->first()->getUnitPrice());
        self::assertCount(1, $order->getItems()->first()->getAddons());
        self::assertCount(1, $order->getStatusHistory());
        self::assertNotNull($order->getVoucherRedemption());
        self::assertSame(PaymentStatus::PENDING, $order->getPayment()?->getStatus());
        self::assertNull($order->getPayment()?->getTransactionReference());

        $updates = self::getContainer()->get(RecordingMercureHub::class)->updates();
        self::assertCount(2, $updates);
        self::assertSame(['orders/'.$order->getId().'/status'], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame('order.status_changed', $updates[0]->getType());
        $statusPayload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('placed', $statusPayload['status'] ?? null);
        self::assertNull($statusPayload['previous_status'] ?? null);

        self::assertSame(['restaurant/'.$order->getRestaurant()->getId().'/orders'], $updates[1]->getTopics());
        self::assertTrue($updates[1]->isPrivate());
        self::assertSame('order.placed', $updates[1]->getType());
        $restaurantPayload = json_decode($updates[1]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($order->getOrderNumber(), $restaurantPayload['order_number'] ?? null);
        self::assertSame($order->getTotalAmount(), $restaurantPayload['total_amount'] ?? null);
    }

    public function testPreviewReturnsTheSameServerAuthoritativeTotalsWithoutCreatingAnOrder(): void
    {
        $token = $this->uuid();
        $preview = $this->preview('gcash', $token);

        self::assertResponseIsSuccessful();
        self::assertTrue($preview['quote']['address_covered'] ?? false);
        self::assertSame('gcash', $preview['quote']['payment_method'] ?? null);
        self::assertSame('260.00', $preview['quote']['subtotal'] ?? null);
        self::assertSame('130.00', $preview['quote']['items'][0]['unit_total'] ?? null);
        self::assertSame('260.00', $preview['quote']['items'][0]['line_total'] ?? null);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Order::class)->count([]));

        $checkout = $this->checkout('gcash', $token);
        self::assertResponseStatusCodeSame(201);
        foreach (['subtotal', 'delivery_fee', 'service_fee', 'discount_amount', 'tip_amount', 'total_amount'] as $amount) {
            self::assertSame($preview['quote'][$amount] ?? null, $checkout['order'][$amount] ?? null, $amount);
        }
        self::assertSame('paid', $checkout['order']['payment_status'] ?? null);
    }

    public function testCardCheckoutIsPaidImmediatelyAndRetryIsIdempotent(): void
    {
        $token = $this->uuid();
        $first = $this->checkout('card', $token);
        self::assertResponseStatusCodeSame(201);
        $firstOrderId = $first['order']['id'] ?? null;
        self::assertCount(2, self::getContainer()->get(RecordingMercureHub::class)->updates());

        $second = $this->checkout('card', $token);
        self::assertResponseIsSuccessful();
        self::assertFalse($second['created'] ?? true);
        self::assertSame($firstOrderId, $second['order']['id'] ?? null);

        $order = $this->order((string) $firstOrderId);
        self::assertSame(PaymentStatus::PAID, $order->getPayment()?->getStatus());
        self::assertNotNull($order->getPayment()?->getPaidAt());
        self::assertStringStartsWith('SIMULATED-', (string) $order->getPayment()?->getTransactionReference());

        $count = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Order::class)
            ->count(['checkoutToken' => $token]);
        self::assertSame(1, $count);
    }

    public function testCheckoutRejectsAnAddressOutsideEveryActiveZone(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $zone = $entityManager->getRepository(DeliveryZone::class)->findOneBy(['name' => self::ZONE_NAME]);
        self::assertInstanceOf(DeliveryZone::class, $zone);
        $zone->setIsActive(false);
        $entityManager->flush();

        $response = $this->checkout('gcash', $this->uuid());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The selected address is outside the active delivery zones.', $response['message'] ?? null);
        self::assertSame(0, $entityManager->getRepository(Order::class)->count([
            'customer' => $entityManager->find(Customer::class, $this->fixture['customer_id']),
        ]));
    }

    public function testPreviewSurfacesAnAddressOutsideEveryActiveZoneWithoutCreatingAnOrder(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $zone = $entityManager->getRepository(DeliveryZone::class)->findOneBy(['name' => self::ZONE_NAME]);
        self::assertInstanceOf(DeliveryZone::class, $zone);
        $zone->setIsActive(false);
        $entityManager->flush();

        $response = $this->preview('cod', $this->uuid());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The selected address is outside the active delivery zones.', $response['message'] ?? null);
        self::assertSame(0, $entityManager->getRepository(Order::class)->count([]));
    }

    public function testCheckoutRejectsAClosedRestaurantWithoutCreatingAnOrder(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $restaurant = $entityManager->find(Restaurant::class, $this->fixture['restaurant_id']);
        self::assertInstanceOf(Restaurant::class, $restaurant);
        $restaurant->setOperatingStatus(RestaurantOperatingStatus::CLOSED);
        $entityManager->flush();

        $response = $this->checkout('cod', $this->uuid());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('This restaurant is currently closed.', $response['message'] ?? null);
        self::assertSame(0, $entityManager->getRepository(Order::class)->count([]));
    }

    public function testCheckoutEnforcesOperatingHoursOnTheServer(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $restaurant = $entityManager->find(Restaurant::class, $this->fixture['restaurant_id']);
        self::assertInstanceOf(Restaurant::class, $restaurant);
        $hours = $entityManager->getRepository(RestaurantOperatingHour::class)->findOneBy(['restaurant' => $restaurant]);
        self::assertInstanceOf(RestaurantOperatingHour::class, $hours);
        $hours->setOpensAt(new \DateTimeImmutable('00:00:00'))->setClosesAt(new \DateTimeImmutable('00:00:01'));
        $entityManager->flush();

        $response = $this->checkout('gcash', $this->uuid());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('This restaurant is outside its operating hours.', $response['message'] ?? null);
        self::assertSame(0, $entityManager->getRepository(Order::class)->count([]));
    }

    public function testCheckoutRejectsAnUnavailableMenuItemWithoutCreatingAnOrder(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $item = $entityManager->find(MenuItem::class, $this->fixture['item_id']);
        self::assertInstanceOf(MenuItem::class, $item);
        $item->setIsAvailable(false);
        $entityManager->flush();

        $response = $this->checkout('card', $this->uuid());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Checkout Test Meal is not currently available.', $response['message'] ?? null);
        self::assertSame(0, $entityManager->getRepository(Order::class)->count([]));
    }

    /** @return array<string, mixed> */
    private function checkout(string $method, string $token, ?string $voucher = null): array
    {
        $this->client->jsonRequest('POST', '/api/customer/checkout', $this->checkoutBody($method, $token, $voucher), server: $this->auth($this->login()));

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function preview(string $method, string $token, ?string $voucher = null): array
    {
        $this->client->jsonRequest('POST', '/api/customer/checkout/preview', $this->checkoutBody($method, $token, $voucher), server: $this->auth($this->login()));

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function checkoutBody(string $method, string $token, ?string $voucher = null): array
    {
        $body = [
            'checkout_token' => $token,
            'restaurant_id' => $this->fixture['restaurant_id'],
            'customer_address_id' => $this->fixture['address_id'],
            'payment_method' => $method,
            'tip_amount' => '20.00',
            'customer_notes' => 'Please call on arrival.',
            'items' => [[
                'menu_item_id' => $this->fixture['item_id'],
                'variant_id' => $this->fixture['variant_id'],
                'addon_ids' => [$this->fixture['addon_id']],
                'quantity' => 2,
                'special_instructions' => 'No onions',
            ]],
        ];
        if (null !== $voucher) {
            $body['voucher_code'] = $voucher;
        }

        return $body;
    }

    /** @return array<string, string> */
    private function createFixture(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186100001', UserRole::RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Checkout Test Restaurant')
            ->setSlug('checkout-test-restaurant')
            ->setAddress('Test pickup')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setMinOrderAmount('100.00')
            ->setCommissionRate('15.00')
            ->setApprovalStatus(ApprovalStatus::APPROVED)
            ->setOperatingStatus(RestaurantOperatingStatus::OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $hours = (new RestaurantOperatingHour())
            ->setRestaurant($restaurant)
            ->setDayOfWeek((int) $now->format('w'))
            ->setOpensAt(new \DateTimeImmutable('00:00:00'))
            ->setClosesAt(new \DateTimeImmutable('23:59:59'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurant->addOperatingHour($hours);

        $category = (new MenuCategory())
            ->setRestaurant($restaurant)
            ->setName('Checkout Test Category')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $item = (new MenuItem())
            ->setMenuCategory($category)
            ->setName('Checkout Test Meal')
            ->setBasePrice('100.00')
            ->setIsAvailable(true)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $variant = (new MenuItemVariant())
            ->setMenuItem($item)
            ->setName('Large')
            ->setPriceDelta('20.00')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $addon = (new MenuItemAddon())
            ->setMenuItem($item)
            ->setName('Extra sauce')
            ->setPrice('10.00')
            ->setIsAvailable(true)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186100002', UserRole::CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Checkout Test Address')
            ->setAddressLine('Test delivery')
            ->setLatitude('14.6000000')
            ->setLongitude('120.9850000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $zone = (new DeliveryZone())
            ->setName(self::ZONE_NAME)
            ->setPolygon(['type' => 'circle', 'center' => [14.6, 120.985], 'radius_km' => 5])
            ->setIsActive(true)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $voucher = (new Voucher())
            ->setCode(self::VOUCHER_CODE)
            ->setScope(VoucherScope::PLATFORM)
            ->setType(VoucherType::FIXED)
            ->setValue('10.00')
            ->setMinOrderAmount('100.00')
            ->setUsageLimitTotal(10)
            ->setUsageLimitPerCustomer(2)
            ->setIsActive(true)
            ->setStartsAt($now->modify('-1 day'))
            ->setEndsAt($now->modify('+1 day'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);

        foreach ([$restaurantUser, $restaurant, $hours, $category, $item, $variant, $addon, $customerUser, $customer, $address, $zone, $voucher] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [
            'restaurant_id' => (string) $restaurant->getId(),
            'customer_id' => (string) $customer->getId(),
            'address_id' => (string) $address->getId(),
            'item_id' => (string) $item->getId(),
            'variant_id' => (string) $variant->getId(),
            'addon_id' => (string) $addon->getId(),
        ];
    }

    private function user(string $email, string $phone, UserRole $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Checkout Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT))
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function login(): string
    {
        $this->client->jsonRequest('POST', '/api/login', ['login' => self::CUSTOMER_EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['token'];
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function order(string $id): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $id);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function cleanup(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $email = self::CUSTOMER_EMAIL;
        $customerOrder = 'SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?))';
        foreach ([
            "DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id IN ($customerOrder))",
            "DELETE FROM voucher_redemptions WHERE order_id IN ($customerOrder)",
            "DELETE FROM payments WHERE order_id IN ($customerOrder)",
            "DELETE FROM order_item_addons WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id IN ($customerOrder))",
            "DELETE FROM order_items WHERE order_id IN ($customerOrder)",
            "DELETE FROM order_status_history WHERE order_id IN ($customerOrder)",
            "DELETE FROM orders WHERE id IN ($customerOrder)",
        ] as $sql) {
            $placeholderCount = substr_count($sql, '?');
            $connection->executeStatement($sql, array_fill(0, $placeholderCount, $email));
        }
        $connection->executeStatement('DELETE FROM menu_item_addons WHERE menu_item_id IN (SELECT id FROM menu_items WHERE name = ?)', ['Checkout Test Meal']);
        $connection->executeStatement('DELETE FROM menu_item_variants WHERE menu_item_id IN (SELECT id FROM menu_items WHERE name = ?)', ['Checkout Test Meal']);
        $connection->executeStatement('DELETE FROM menu_items WHERE name = ?', ['Checkout Test Meal']);
        $connection->executeStatement('DELETE FROM menu_categories WHERE name = ?', ['Checkout Test Category']);
        $connection->executeStatement('DELETE FROM restaurant_operating_hours WHERE restaurant_id IN (SELECT id FROM restaurants WHERE slug = ?)', ['checkout-test-restaurant']);
        $connection->executeStatement('DELETE FROM customer_addresses WHERE label = ?', ['Checkout Test Address']);
        $connection->executeStatement('DELETE FROM vouchers WHERE code = ?', [self::VOUCHER_CODE]);
        $connection->executeStatement('DELETE FROM delivery_zones WHERE name = ?', [self::ZONE_NAME]);
        $connection->executeStatement('DELETE FROM customers WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::CUSTOMER_EMAIL]);
        $connection->executeStatement('DELETE FROM restaurants WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [self::RESTAURANT_EMAIL]);
        $connection->executeStatement('DELETE FROM users WHERE email IN (?, ?)', [self::CUSTOMER_EMAIL, self::RESTAURANT_EMAIL]);
    }
}
