<?php

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\DeliveryZone;
use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use App\Entity\MenuItemAddon;
use App\Entity\MenuItemVariant;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\RestaurantOperatingHour;
use App\Entity\User;
use App\Entity\Voucher;
use App\Tests\Double\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The full customer path: add to cart from the restaurant page, then check out.
 * Cart: 2 × Checkout Test Meal (100.00) + Large (+20.00) + Extra sauce (+10.00) = 260.00.
 */
class CheckoutControllerTest extends WebTestCase
{
    private const ZONE_NAME = 'Checkout Test Zone';
    private const VOUCHER_CODE = 'CHECKOUT10';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    /** @var array<string, string> */
    private array $fixture;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(RecordingMercureHub::class)->reset();
        $this->fixture = $this->createFixture();
        $this->client->loginUser($this->entityManager->find(User::class, $this->fixture['customer_user_id']), 'main');
        $this->addToCart();
    }

    public function testCodCheckoutRecomputesAndSnapshotsTheOrderWithPendingPayment(): void
    {
        $this->placeOrder(Payment::METHOD_COD, self::VOUCHER_CODE);

        self::assertResponseRedirects();
        $order = $this->onlyOrder();
        self::assertSame('260.00', $order->getSubtotal());
        self::assertSame('10.00', $order->getDiscountAmount());
        self::assertSame('20.00', $order->getTipAmount());
        self::assertSame('Please call on arrival.', $order->getCustomerNotes());
        self::assertCount(1, $order->getItems());
        self::assertSame('120.00', $order->getItems()->first()->getUnitPrice());
        self::assertSame('No onions', $order->getItems()->first()->getSpecialInstructions());
        self::assertCount(1, $order->getItems()->first()->getAddons());
        self::assertCount(1, $order->getStatusHistory());
        self::assertNotNull($order->getVoucherRedemption());
        self::assertSame(Payment::STATUS_PENDING, $order->getPayment()?->getStatus());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Order '.$order->getOrderNumber().' was placed.');
        self::assertSame(0, $this->cartLines(), 'The cart is emptied after the order.');

        $updates = self::getContainer()->get(RecordingMercureHub::class)->updates();
        self::assertCount(2, $updates);
        self::assertSame(['orders/'.$order->getId().'/status'], $updates[0]->getTopics(), 'Pages following the order refresh.');
        self::assertSame(['restaurant/'.$order->getRestaurant()->getId().'/orders'], $updates[1]->getTopics(), 'The restaurant queue learns of the order.');
        foreach ($updates as $update) {
            self::assertSame('<turbo-stream action="refresh"></turbo-stream>', $update->getData());
            self::assertTrue($update->isPrivate());
        }
    }

    public function testAnUnreachableLiveUpdateHubDoesNotBreakCheckout(): void
    {
        self::getContainer()->get(RecordingMercureHub::class)->goDown();

        $crawler = $this->client->request('GET', '/checkout');
        $this->client->submit($crawler->selectButton('Place order')->form());

        self::assertResponseRedirects();
        $order = $this->onlyOrder();
        self::assertSame(0, $this->cartLines(), 'The cart is emptied even though no live update went out.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Order '.$order->getOrderNumber().' was placed.');
    }

    public function testPreviewShowsTheTotalsTheOrderIsPlacedWith(): void
    {
        $crawler = $this->client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->submit($crawler->selectButton('Update total')->form([
            'checkout_form[paymentMethod]' => Payment::METHOD_GCASH,
            'checkout_form[tipAmount]' => '20.00',
        ]));
        self::assertResponseIsSuccessful();
        $previewTotal = $this->total($crawler);
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));

        $this->client->submit($crawler->selectButton('Place order')->form());
        self::assertResponseRedirects();
        $order = $this->onlyOrder();
        self::assertSame('₱'.$order->getTotalAmount(), $previewTotal);
        self::assertSame(Payment::STATUS_PAID, $order->getPayment()?->getStatus());
    }

    public function testSubmittingTheSameCheckoutTwicePlacesOneOrder(): void
    {
        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form(['checkout_form[paymentMethod]' => Payment::METHOD_CARD]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->submit($form);

        self::assertSame(1, $this->entityManager->getRepository(Order::class)->count([]));
        $order = $this->onlyOrder();
        self::assertSame(Payment::STATUS_PAID, $order->getPayment()?->getStatus());
        self::assertStringStartsWith('SIMULATED-', (string) $order->getPayment()?->getTransactionReference());
    }

    public function testWithNoActiveZoneEveryAddressIsAccepted(): void
    {
        $this->entityManager->getRepository(DeliveryZone::class)->findOneBy(['name' => self::ZONE_NAME])->setIsActive(false);
        $this->entityManager->getRepository(CustomerAddress::class)->findOneBy(['label' => 'Checkout Test Address'])
            ->setLatitude('9.3068000')->setLongitude('123.3054000');
        $this->entityManager->flush();

        $this->client->request('GET', '/checkout');
        self::assertSelectorNotExists('.error');
        $this->placeOrder(Payment::METHOD_GCASH);
        self::assertSame(1, $this->entityManager->getRepository(Order::class)->count([]), 'An address far from any zone is accepted while no zone is active.');
    }

    public function testAnAddressOutsideEveryActiveZoneIsRejected(): void
    {
        // About 600 km from the 5 km zone around the test restaurant.
        $this->entityManager->getRepository(CustomerAddress::class)->findOneBy(['label' => 'Checkout Test Address'])
            ->setLatitude('9.3068000')->setLongitude('123.3054000');
        $this->entityManager->flush();

        $this->client->request('GET', '/checkout');
        self::assertSelectorTextContains('.error', 'The selected address is outside the active delivery zones.');

        $this->placeOrder(Payment::METHOD_GCASH);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'The selected address is outside the active delivery zones.');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
    }

    public function testAClosedRestaurantIsRejected(): void
    {
        $this->entityManager->find(Restaurant::class, $this->fixture['restaurant_id'])->setOperatingStatus(Restaurant::OPERATING_CLOSED);
        $this->entityManager->flush();

        $this->placeOrder(Payment::METHOD_COD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'This restaurant is currently closed.');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
    }

    public function testManualOpenStatusIsUsedWhenNoOperatingHoursAreConfigured(): void
    {
        $restaurant = $this->entityManager->find(Restaurant::class, $this->fixture['restaurant_id']);
        $hours = $this->entityManager->getRepository(RestaurantOperatingHour::class)->findOneBy(['restaurant' => $restaurant]);
        $restaurant->removeOperatingHour($hours);
        $this->entityManager->remove($hours);
        $this->entityManager->flush();

        $this->placeOrder(Payment::METHOD_COD);

        self::assertResponseRedirects();
        self::assertSame(Order::STATUS_PLACED, $this->onlyOrder()->getStatus());
    }

    public function testOperatingHoursAreEnforced(): void
    {
        $this->entityManager->getRepository(RestaurantOperatingHour::class)->findOneBy([])
            ->setOpensAt(new \DateTimeImmutable('00:00:00'))->setClosesAt(new \DateTimeImmutable('00:00:01'));
        $this->entityManager->flush();

        $this->placeOrder(Payment::METHOD_GCASH);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'This restaurant is outside its operating hours.');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
    }

    public function testAnItemThatBecameUnavailableIsRejected(): void
    {
        $this->entityManager->find(MenuItem::class, $this->fixture['item_id'])->setIsAvailable(false);
        $this->entityManager->flush();

        $this->placeOrder(Payment::METHOD_CARD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'Checkout Test Meal is not currently available.');
        self::assertSame(0, $this->entityManager->getRepository(Order::class)->count([]));
    }

    public function testCheckoutNeedsAnAddress(): void
    {
        $address = $this->entityManager->find(CustomerAddress::class, $this->fixture['address_id']);
        $this->entityManager->remove($address);
        $this->entityManager->flush();

        $this->client->request('GET', '/checkout');
        self::assertSelectorTextContains('main', 'Add a delivery address to your account before checking out.');
    }

    /** Adds the meal from the restaurant page: Large, Extra sauce, quantity 2, a note. */
    private function addToCart(): void
    {
        $crawler = $this->client->request('GET', '/restaurants/checkout-test-restaurant');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="add-'.$this->fixture['item_id'].'"]')->form();
        $form['variant'] = $this->fixture['variant_id'];
        $form['addons[0]']->tick();
        $form['quantity'] = '2';
        $form['instructions'] = 'No onions';
        $this->client->submit($form);
        self::assertResponseRedirects('/restaurants/checkout-test-restaurant');
        self::assertSame(1, $this->cartLines());
    }

    private function placeOrder(string $method, ?string $voucher = null): void
    {
        $crawler = $this->client->request('GET', '/checkout');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Place order')->form([
            'checkout_form[paymentMethod]' => $method,
            'checkout_form[tipAmount]' => '20.00',
            'checkout_form[voucherCode]' => (string) $voucher,
            'checkout_form[notes]' => 'Please call on arrival.',
        ]));
    }

    private function total(Crawler $crawler): string
    {
        return $crawler->filter('#totals dt:contains("Total") + dd')->text();
    }

    private function cartLines(): int
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Customer::class, $this->fixture['customer_id'])->getCartItems()->count();
    }

    private function onlyOrder(): Order
    {
        $this->entityManager->clear();
        $orders = $this->entityManager->getRepository(Order::class)->findAll();
        self::assertCount(1, $orders);

        return $orders[0];
    }

    /** @return array<string, string> */
    private function createFixture(): array
    {
        $now = new \DateTimeImmutable();
        $restaurantUser = $this->user('checkout-restaurant@foodjett.test', '+639186100001', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)->setName('Checkout Test Restaurant')->setSlug('checkout-test-restaurant')
            ->setAddress('Test pickup')->setLatitude('14.5995000')->setLongitude('120.9842000')
            ->setMinOrderAmount('100.00')->setCommissionRate('15.00')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $hours = (new RestaurantOperatingHour())
            ->setRestaurant($restaurant)->setDayOfWeek((int) $now->format('w'))
            ->setOpensAt(new \DateTimeImmutable('00:00:00'))->setClosesAt(new \DateTimeImmutable('23:59:59'))
            ->setCreatedAt($now)->setUpdatedAt($now);
        $restaurant->addOperatingHour($hours);
        $category = (new MenuCategory())->setRestaurant($restaurant)->setName('Checkout Test Category')->setCreatedAt($now)->setUpdatedAt($now);
        $restaurant->addMenuCategory($category);
        $item = (new MenuItem())->setMenuCategory($category)->setName('Checkout Test Meal')->setBasePrice('100.00')
            ->setIsAvailable(true)->setCreatedAt($now)->setUpdatedAt($now);
        $category->addItem($item);
        $variant = (new MenuItemVariant())->setMenuItem($item)->setName('Large')->setPriceDelta('20.00')->setCreatedAt($now)->setUpdatedAt($now);
        $item->addVariant($variant);
        $addon = (new MenuItemAddon())->setMenuItem($item)->setName('Extra sauce')->setPrice('10.00')->setIsAvailable(true)
            ->setCreatedAt($now)->setUpdatedAt($now);
        $item->addAddon($addon);

        $customerUser = $this->user('checkout-customer@foodjett.test', '+639186100002', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())->setCustomer($customer)->setLabel('Checkout Test Address')->setAddressLine('Test delivery')
            ->setLatitude('14.6000000')->setLongitude('120.9850000')->setIsDefault(true)->setCreatedAt($now)->setUpdatedAt($now);
        $zone = (new DeliveryZone())->setName(self::ZONE_NAME)
            ->setPolygon(['type' => 'circle', 'center' => [14.6, 120.985], 'radius_km' => 5])
            ->setIsActive(true)->setCreatedAt($now)->setUpdatedAt($now);
        $voucher = (new Voucher())->setCode(self::VOUCHER_CODE)->setScope(Voucher::SCOPE_PLATFORM)->setType(Voucher::TYPE_FIXED)
            ->setValue('10.00')->setMinOrderAmount('100.00')->setUsageLimitTotal(10)->setUsageLimitPerCustomer(2)
            ->setIsActive(true)->setStartsAt($now->modify('-1 day'))->setEndsAt($now->modify('+1 day'))
            ->setCreatedAt($now)->setUpdatedAt($now);

        foreach ([$restaurantUser, $restaurant, $hours, $category, $item, $variant, $addon, $customerUser, $customer, $address, $zone, $voucher] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        return [
            'restaurant_id' => (string) $restaurant->getId(),
            'customer_id' => (string) $customer->getId(),
            'customer_user_id' => (string) $customerUser->getId(),
            'address_id' => (string) $address->getId(),
            'item_id' => (string) $item->getId(),
            'variant_id' => (string) $variant->getId(),
        ];
    }

    private function user(string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName('Checkout Test User')->setEmail($email)->setPhone($phone)
            ->setPassword(password_hash('TestPassword123!', PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }
}
