<?php

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\CustomerAddress;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Restaurant;
use App\Entity\RiderPoolOffer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Customer cancellation from the order page, with the matching payment outcome. */
class OrderCancellationTest extends WebTestCase
{
    private const PASSWORD = 'TestPassword123!';
    private const CUSTOMER_EMAIL = 'cancel-customer@foodjett.test';
    private const RESTAURANT_EMAIL = 'cancel-restaurant@foodjett.test';

    private KernelBrowser $client;
    private string $customerId;
    private string $customerUserId;
    private string $restaurantId;
    private string $addressId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->createFixture();
        $this->client->loginUser(self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->customerUserId), 'main');
    }

    public function testCustomerCancelsPlacedPaidOrderAndReceivesFullRefund(): void
    {
        $order = $this->createOrder(Order::STATUS_PLACED, Payment::METHOD_CARD, Payment::STATUS_PAID);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Cancel order')->form([
            'order_cancel_form[reason]' => 'Changed my mind',
        ]));

        self::assertResponseRedirects('/orders/'.$order->getId());
        $cancelled = $this->reload((string) $order->getId());
        self::assertSame(Order::STATUS_CANCELLED_BY_CUSTOMER, $cancelled->getStatus());
        self::assertSame('Changed my mind', $cancelled->getCancellationReason());
        self::assertSame(Payment::STATUS_REFUNDED, $cancelled->getPayment()?->getStatus());
        self::assertSame($cancelled->getPayment()?->getAmount(), $cancelled->getPayment()?->getRefundedAmount());
        self::assertCount(1, $cancelled->getPayment()?->getStatusHistory());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your order was cancelled.');
        self::assertSelectorNotExists('form[name="order_cancel_form"]');
    }

    public function testFindingRiderCancellationRequiresCustomerNotifiedEscalation(): void
    {
        $order = $this->createOrder(Order::STATUS_FINDING_RIDER, Payment::METHOD_COD, Payment::STATUS_PENDING, RiderPoolOffer::STAGE_INITIAL);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        self::assertCount(0, $crawler->filter('form[name="order_cancel_form"]'), 'No cancel form while the rider search is still running.');
        // A hand-made POST is refused too.
        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->request('POST', '/orders/'.$order->getId().'/cancel', ['order_cancel_form' => ['reason' => '']]);
        self::assertSame(Order::STATUS_FINDING_RIDER, $this->reload((string) $order->getId())->getStatus());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->find(RiderPoolOffer::class, $order->getRiderPoolOffer()?->getId())
            ->setEscalationStage(RiderPoolOffer::STAGE_CUSTOMER_NOTIFIED);
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());
        $this->client->submit($crawler->selectButton('Cancel order')->form());
        $cancelled = $this->reload((string) $order->getId());
        self::assertSame(Order::STATUS_CANCELLED_BY_CUSTOMER, $cancelled->getStatus());
        self::assertSame(Payment::STATUS_FAILED, $cancelled->getPayment()?->getStatus());
        self::assertSame('0.00', $cancelled->getPayment()?->getRefundedAmount());
    }

    private function createFixture(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $customerUser = $this->user(self::CUSTOMER_EMAIL, '+639186200001', User::ACCOUNT_CUSTOMER, $now);
        $customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $address = (new CustomerAddress())
            ->setCustomer($customer)
            ->setLabel('Cancellation Test Address')
            ->setAddressLine('Test address')
            ->setLatitude('14.6000000')
            ->setLongitude('120.9850000')
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $restaurantUser = $this->user(self::RESTAURANT_EMAIL, '+639186200002', User::ACCOUNT_RESTAURANT, $now);
        $restaurant = (new Restaurant())
            ->setUser($restaurantUser)
            ->setName('Cancellation Test Restaurant')
            ->setSlug('cancellation-test-restaurant')
            ->setAddress('Test restaurant')
            ->setLatitude('14.5995000')
            ->setLongitude('120.9842000')
            ->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setOperatingStatus(Restaurant::OPERATING_OPEN)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        foreach ([$customerUser, $customer, $address, $restaurantUser, $restaurant] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $this->customerId = (string) $customer->getId();
        $this->customerUserId = (string) $customerUser->getId();
        $this->addressId = (string) $address->getId();
        $this->restaurantId = (string) $restaurant->getId();
    }

    private function createOrder(
        string $status,
        string $method,
        string $paymentStatus,
        ?string $stage = null,
    ): Order {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $order = (new Order())
            ->setOrderNumber('CANCEL-'.bin2hex(random_bytes(5)))
            ->setCustomer($entityManager->find(Customer::class, $this->customerId))
            ->setRestaurant($entityManager->find(Restaurant::class, $this->restaurantId))
            ->setCustomerAddress($entityManager->find(CustomerAddress::class, $this->addressId))
            ->setStatus($status)
            ->setSubtotal('100.00')
            ->setDeliveryFee('35.00')
            ->setTotalAmount('135.00')
            ->setPaymentMethod($method)
            ->setPlacedAt($now)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $payment = (new Payment())
            ->setOrder($order)
            ->setMethod($method)
            ->setStatus($paymentStatus)
            ->setAmount('135.00')
            ->setPaidAt(Payment::STATUS_PAID === $paymentStatus ? $now : null)
            ->setTransactionReference(Payment::STATUS_PAID === $paymentStatus ? 'SIMULATED-CANCEL' : null)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $order->setPayment($payment);
        $entityManager->persist($order);
        $entityManager->persist($payment);
        if (null !== $stage) {
            $offer = (new RiderPoolOffer())
                ->setOrder($order)
                ->setEscalationStage($stage)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);
            $order->setRiderPoolOffer($offer);
            $entityManager->persist($offer);
        }
        $entityManager->flush();

        return $order;
    }

    private function user(string $email, string $phone, string $role, \DateTimeImmutable $now): User
    {
        return (new User())
            ->setName('Cancellation Test User')
            ->setEmail($email)
            ->setPhone($phone)
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)
            ->setStatus(User::STATUS_ACTIVE)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
    }

    private function reload(string $id): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $id);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }
}
