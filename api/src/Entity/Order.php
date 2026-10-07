<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderActor;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'orders',
    indexes: [new ORM\Index(name: 'orders_status_index', columns: ['status'])],
)]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $orderNumber;

    #[ORM\Column(type: Types::GUID, unique: true, nullable: true, columnDefinition: 'CHAR(36) DEFAULT NULL')]
    private ?string $checkoutToken = null;

    #[ORM\ManyToOne(inversedBy: 'orders', targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\ManyToOne(inversedBy: 'orders', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Restaurant $restaurant;

    #[ORM\ManyToOne(inversedBy: 'orders', targetEntity: CustomerAddress::class)]
    #[ORM\JoinColumn(name: 'customer_address_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CustomerAddress $customerAddress;

    #[ORM\ManyToOne(inversedBy: 'orders', targetEntity: Rider::class)]
    #[ORM\JoinColumn(name: 'rider_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Rider $rider = null;

    #[ORM\Column(length: 64, enumType: OrderStatus::class, options: ['default' => 'placed'])]
    private OrderStatus $status = OrderStatus::PLACED;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $subtotal;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $deliveryFee;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $serviceFee = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $discountAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $tipAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $totalAmount;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $commissionAmount = null;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customerNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(length: 32, nullable: true, enumType: OrderActor::class)]
    private ?OrderActor $cancelledBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, columnDefinition: 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $placedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $estimatedPrepMinutes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $estimatedReadyAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $prepExtendedMinutes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $riderSearchStartedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $riderAssignedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $riderArrivedRestaurantAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $pickedUpAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pickupCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proofOfDeliveryPath = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'])]
    private Collection $items;

    /** @var Collection<int, OrderStatusHistory> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderStatusHistory::class, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $statusHistory;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: RiderPoolOffer::class, cascade: ['persist'])]
    private ?RiderPoolOffer $riderPoolOffer = null;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: Payment::class, cascade: ['persist'])]
    private ?Payment $payment = null;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: RiderEarning::class, cascade: ['persist'])]
    private ?RiderEarning $riderEarning = null;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: VoucherRedemption::class, cascade: ['persist'])]
    private ?VoucherRedemption $voucherRedemption = null;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: RestaurantReview::class, cascade: ['persist'])]
    private ?RestaurantReview $restaurantReview = null;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: RiderReview::class, cascade: ['persist'])]
    private ?RiderReview $riderReview = null;

    /** @var Collection<int, OrderReport> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderReport::class, cascade: ['persist'])]
    private Collection $reports;

    /** @var Collection<int, Conversation> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: Conversation::class, cascade: ['persist'])]
    private Collection $conversations;

    /** @var Collection<int, RiderPoolDecline> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: RiderPoolDecline::class, cascade: ['persist'])]
    private Collection $riderPoolDeclines;

    public function __construct()
    {
        $this->placedAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
        $this->riderPoolDeclines = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->conversations = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getOrderNumber(): string { return $this->orderNumber; }
    public function setOrderNumber(string $orderNumber): self { $this->orderNumber = $orderNumber; return $this; }
    public function getCheckoutToken(): ?string { return $this->checkoutToken; }
    public function setCheckoutToken(?string $checkoutToken): self { $this->checkoutToken = $checkoutToken; return $this; }
    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $customer): self { $this->customer = $customer; return $this; }
    public function getRestaurant(): Restaurant { return $this->restaurant; }
    public function setRestaurant(Restaurant $restaurant): self { $this->restaurant = $restaurant; return $this; }
    public function getCustomerAddress(): CustomerAddress { return $this->customerAddress; }
    public function setCustomerAddress(CustomerAddress $customerAddress): self { $this->customerAddress = $customerAddress; return $this; }
    public function getRider(): ?Rider { return $this->rider; }
    public function setRider(?Rider $rider): self { $this->rider = $rider; return $this; }
    public function getStatus(): OrderStatus { return $this->status; }
    public function setStatus(OrderStatus $status): self { $this->status = $status; return $this; }
    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getDeliveryFee(): string { return $this->deliveryFee; }
    public function setDeliveryFee(string $deliveryFee): self { $this->deliveryFee = $deliveryFee; return $this; }
    public function getServiceFee(): string { return $this->serviceFee; }
    public function setServiceFee(string $serviceFee): self { $this->serviceFee = $serviceFee; return $this; }
    public function getDiscountAmount(): string { return $this->discountAmount; }
    public function setDiscountAmount(string $discountAmount): self { $this->discountAmount = $discountAmount; return $this; }
    public function getTipAmount(): string { return $this->tipAmount; }
    public function setTipAmount(string $tipAmount): self { $this->tipAmount = $tipAmount; return $this; }
    public function getTotalAmount(): string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): self { $this->totalAmount = $totalAmount; return $this; }
    public function getCommissionAmount(): ?string { return $this->commissionAmount; }
    public function setCommissionAmount(?string $commissionAmount): self { $this->commissionAmount = $commissionAmount; return $this; }
    public function getPaymentMethod(): PaymentMethod { return $this->paymentMethod; }
    public function setPaymentMethod(PaymentMethod $paymentMethod): self { $this->paymentMethod = $paymentMethod; return $this; }
    public function getCustomerNotes(): ?string { return $this->customerNotes; }
    public function setCustomerNotes(?string $customerNotes): self { $this->customerNotes = $customerNotes; return $this; }
    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $rejectionReason): self { $this->rejectionReason = $rejectionReason; return $this; }
    public function getCancellationReason(): ?string { return $this->cancellationReason; }
    public function setCancellationReason(?string $cancellationReason): self { $this->cancellationReason = $cancellationReason; return $this; }
    public function getCancelledBy(): ?OrderActor { return $this->cancelledBy; }
    public function setCancelledBy(?OrderActor $cancelledBy): self { $this->cancelledBy = $cancelledBy; return $this; }
    public function getPlacedAt(): \DateTimeImmutable { return $this->placedAt; }
    public function setPlacedAt(\DateTimeImmutable $placedAt): self { $this->placedAt = $placedAt; return $this; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): self { $this->acceptedAt = $acceptedAt; return $this; }
    public function getEstimatedPrepMinutes(): ?int { return $this->estimatedPrepMinutes; }
    public function setEstimatedPrepMinutes(?int $estimatedPrepMinutes): self { $this->estimatedPrepMinutes = $estimatedPrepMinutes; return $this; }
    public function getEstimatedReadyAt(): ?\DateTimeImmutable { return $this->estimatedReadyAt; }
    public function setEstimatedReadyAt(?\DateTimeImmutable $estimatedReadyAt): self { $this->estimatedReadyAt = $estimatedReadyAt; return $this; }
    public function getPrepExtendedMinutes(): ?int { return $this->prepExtendedMinutes; }
    public function setPrepExtendedMinutes(?int $prepExtendedMinutes): self { $this->prepExtendedMinutes = $prepExtendedMinutes; return $this; }
    public function getReadyAt(): ?\DateTimeImmutable { return $this->readyAt; }
    public function setReadyAt(?\DateTimeImmutable $readyAt): self { $this->readyAt = $readyAt; return $this; }
    public function getRiderSearchStartedAt(): ?\DateTimeImmutable { return $this->riderSearchStartedAt; }
    public function setRiderSearchStartedAt(?\DateTimeImmutable $value): self { $this->riderSearchStartedAt = $value; return $this; }
    public function getRiderAssignedAt(): ?\DateTimeImmutable { return $this->riderAssignedAt; }
    public function setRiderAssignedAt(?\DateTimeImmutable $value): self { $this->riderAssignedAt = $value; return $this; }
    public function getRiderArrivedRestaurantAt(): ?\DateTimeImmutable { return $this->riderArrivedRestaurantAt; }
    public function setRiderArrivedRestaurantAt(?\DateTimeImmutable $value): self { $this->riderArrivedRestaurantAt = $value; return $this; }
    public function getPickedUpAt(): ?\DateTimeImmutable { return $this->pickedUpAt; }
    public function setPickedUpAt(?\DateTimeImmutable $pickedUpAt): self { $this->pickedUpAt = $pickedUpAt; return $this; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): self { $this->deliveredAt = $deliveredAt; return $this; }
    public function getPickupCode(): ?string { return $this->pickupCode; }
    public function setPickupCode(?string $pickupCode): self { $this->pickupCode = $pickupCode; return $this; }
    public function getProofOfDeliveryPath(): ?string { return $this->proofOfDeliveryPath; }
    public function setProofOfDeliveryPath(?string $value): self { $this->proofOfDeliveryPath = $value; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection { return $this->items; }
    public function addItem(OrderItem $item): self { if (!$this->items->contains($item)) { $this->items->add($item); $item->setOrder($this); } return $this; }
    /** @return Collection<int, OrderStatusHistory> */
    public function getStatusHistory(): Collection { return $this->statusHistory; }
    public function addStatusHistory(OrderStatusHistory $history): self { if (!$this->statusHistory->contains($history)) { $this->statusHistory->add($history); $history->setOrder($this); } return $this; }
    public function getRiderPoolOffer(): ?RiderPoolOffer { return $this->riderPoolOffer; }
    public function setRiderPoolOffer(?RiderPoolOffer $offer): self { $this->riderPoolOffer = $offer; if ($offer !== null && $offer->getOrder() !== $this) { $offer->setOrder($this); } return $this; }
    public function getPayment(): ?Payment { return $this->payment; }
    public function setPayment(?Payment $payment): self { $this->payment = $payment; if ($payment !== null && $payment->getOrder() !== $this) { $payment->setOrder($this); } return $this; }
    public function getRiderEarning(): ?RiderEarning { return $this->riderEarning; }
    public function setRiderEarning(?RiderEarning $earning): self { $this->riderEarning = $earning; if ($earning !== null && $earning->getOrder() !== $this) { $earning->setOrder($this); } return $this; }
    public function getVoucherRedemption(): ?VoucherRedemption { return $this->voucherRedemption; }
    public function setVoucherRedemption(?VoucherRedemption $redemption): self { $this->voucherRedemption = $redemption; if ($redemption !== null && $redemption->getOrder() !== $this) { $redemption->setOrder($this); } return $this; }
    public function getRestaurantReview(): ?RestaurantReview { return $this->restaurantReview; }
    public function setRestaurantReview(?RestaurantReview $review): self { $this->restaurantReview = $review; if ($review !== null && $review->getOrder() !== $this) { $review->setOrder($this); } return $this; }
    public function getRiderReview(): ?RiderReview { return $this->riderReview; }
    public function setRiderReview(?RiderReview $review): self { $this->riderReview = $review; if ($review !== null && $review->getOrder() !== $this) { $review->setOrder($this); } return $this; }
    /** @return Collection<int, OrderReport> */
    public function getReports(): Collection { return $this->reports; }
    /** @return Collection<int, Conversation> */
    public function getConversations(): Collection { return $this->conversations; }
    /** @return Collection<int, RiderPoolDecline> */
    public function getRiderPoolDeclines(): Collection { return $this->riderPoolDeclines; }
    public function addRiderPoolDecline(RiderPoolDecline $decline): self { if (!$this->riderPoolDeclines->contains($decline)) { $this->riderPoolDeclines->add($decline); $decline->setOrder($this); } return $this; }
}
