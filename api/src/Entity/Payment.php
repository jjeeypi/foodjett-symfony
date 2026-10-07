<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'payment', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(length: 32, enumType: PaymentStatus::class, options: ['default' => 'pending'])]
    private PaymentStatus $status = PaymentStatus::PENDING;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $refundedAmount = '0.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transactionReference = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, PaymentStatusHistory> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: PaymentStatusHistory::class, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $statusHistory;

    public function __construct()
    {
        $this->statusHistory = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getMethod(): PaymentMethod { return $this->method; }
    public function setMethod(PaymentMethod $method): self { $this->method = $method; return $this; }
    public function getStatus(): PaymentStatus { return $this->status; }
    public function setStatus(PaymentStatus $status): self { $this->status = $status; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getRefundedAmount(): string { return $this->refundedAmount; }
    public function setRefundedAmount(string $amount): self { $this->refundedAmount = $amount; return $this; }
    public function getTransactionReference(): ?string { return $this->transactionReference; }
    public function setTransactionReference(?string $reference): self { $this->transactionReference = $reference; return $this; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): self { $this->paidAt = $paidAt; return $this; }
    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }
    public function setRefundedAt(?\DateTimeImmutable $refundedAt): self { $this->refundedAt = $refundedAt; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
    /** @return Collection<int, PaymentStatusHistory> */
    public function getStatusHistory(): Collection { return $this->statusHistory; }
    public function addStatusHistory(PaymentStatusHistory $history): self { if (!$this->statusHistory->contains($history)) { $this->statusHistory->add($history); $history->setPayment($this); } return $this; }
}
