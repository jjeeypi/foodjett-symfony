<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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

abstract class RiderWebTestCase extends WebTestCase
{
    protected const PASSWORD = 'TestPassword123!';
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected User $riderUser;
    protected Rider $rider;
    protected User $otherRiderUser;
    protected Rider $otherRider;
    protected Restaurant $restaurant;
    protected Customer $customer;
    protected CustomerAddress $address;
    protected string $marker;
    /** @var list<string> */
    protected array $uploadedPaths = [];
    private int $orderSequence = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'RAPI-'.bin2hex(random_bytes(5));
        $now = new \DateTimeImmutable();
        $this->riderUser = $this->user('rider', User::ACCOUNT_RIDER, $now);
        $this->rider = $this->rider($this->riderUser, 'RIDER-1', $now);
        $this->otherRiderUser = $this->user('other-rider', User::ACCOUNT_RIDER, $now);
        $this->otherRider = $this->rider($this->otherRiderUser, 'RIDER-2', $now);
        $restaurantUser = $this->user('restaurant', User::ACCOUNT_RESTAURANT, $now);
        $this->restaurant = (new Restaurant())->setUser($restaurantUser)->setName($this->marker.' Kitchen')->setSlug(strtolower($this->marker).'-kitchen')
            ->setAddress('Test pickup')->setLatitude('14.5995000')->setLongitude('120.9842000')->setApprovalStatus(Restaurant::APPROVAL_APPROVED)
            ->setOperatingStatus(Restaurant::OPERATING_OPEN)->setCreatedAt($now)->setUpdatedAt($now);
        $customerUser = $this->user('customer', User::ACCOUNT_CUSTOMER, $now);
        $this->customer = (new Customer())->setUser($customerUser)->setCreatedAt($now)->setUpdatedAt($now);
        $this->address = (new CustomerAddress())->setCustomer($this->customer)->setLabel('Home')->setAddressLine('Test delivery')
            ->setLatitude('14.6091000')->setLongitude('121.0223000')->setIsDefault(true)->setCreatedAt($now)->setUpdatedAt($now);
        $this->persist($this->riderUser, $this->rider, $this->otherRiderUser, $this->otherRider, $restaurantUser, $this->restaurant, $customerUser, $this->customer, $this->address);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->uploadedPaths as $relativePath) {
            $path = dirname(__DIR__, 3).'/public/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_file($path)) { @unlink($path); }
        }
        if ($this->entityManager->getConnection()->isTransactionActive()) { $this->entityManager->getConnection()->rollBack(); }
        parent::tearDown();
    }

    protected function persist(object ...$entities): void { foreach ($entities as $entity) { $this->entityManager->persist($entity); } }

    protected function createOrder(string $status, ?Rider $rider = null, string $method = Payment::METHOD_CARD, ?\DateTimeImmutable $at = null): Order
    {
        $at ??= new \DateTimeImmutable();
        $order = (new Order())->setOrderNumber($this->marker.'-'.(++$this->orderSequence))->setCustomer($this->customer)->setRestaurant($this->restaurant)
            ->setCustomerAddress($this->address)->setRider($rider)->setStatus($status)->setSubtotal('100.00')->setDeliveryFee('35.00')->setServiceFee('0.00')
            ->setDiscountAmount('0.00')->setTipAmount('10.00')->setTotalAmount('145.00')->setCommissionAmount('15.00')->setPaymentMethod($method)
            ->setPlacedAt($at)->setRiderAssignedAt(null === $rider ? null : $at)->setCreatedAt($at)->setUpdatedAt($at);
        $payment = (new Payment())->setOrder($order)->setMethod($method)->setAmount('145.00')
            ->setStatus(Payment::METHOD_COD === $method ? Payment::STATUS_PENDING : Payment::STATUS_PAID)
            ->setPaidAt(Payment::METHOD_COD === $method ? null : $at)->setCreatedAt($at)->setUpdatedAt($at);
        $order->setPayment($payment);
        $this->persist($order, $payment);
        return $order;
    }

    protected function user(string $label, string $role, \DateTimeImmutable $now): User
    {
        return (new User())->setName($this->marker.' '.$label)->setEmail(strtolower($this->marker)."-$label@foodjett.test")
            ->setPhone('+639'.random_int(100000000, 999999999))->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRole($role)->setStatus(User::STATUS_ACTIVE)->setCreatedAt($now)->setUpdatedAt($now);
    }

    private function rider(User $user, string $plate, \DateTimeImmutable $now): Rider
    {
        return (new Rider())->setUser($user)->setVehicleType(Rider::VEHICLE_MOTORCYCLE)->setPlateNumber($plate)
            ->setApprovalStatus(Rider::APPROVAL_APPROVED)->setAvailabilityStatus(Rider::AVAILABILITY_OFFLINE)
            ->setCashOnHand('0.00')->setCashRemitLimit('2000.00')->setCreatedAt($now)->setUpdatedAt($now);
    }
}
