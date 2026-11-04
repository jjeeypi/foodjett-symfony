<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'payments_order_id_unique', columns: ['order_id'])]
class Payment
{
    // Who changed the payment (ACTORS)
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_RIDER = 'rider';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    /** @var list<string> */
    public const ACTORS = [
        self::ACTOR_CUSTOMER,
        self::ACTOR_RIDER,
        self::ACTOR_ADMIN,
        self::ACTOR_SYSTEM,
    ];

    // How the customer pays (METHODS)
    public const METHOD_COD = 'cod';
    public const METHOD_GCASH = 'gcash';
    public const METHOD_CARD = 'card';

    /** @var list<string> */
    public const METHODS = [
        self::METHOD_COD,
        self::METHOD_GCASH,
        self::METHOD_CARD,
    ];

    // Payment status (STATUSES)
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_REFUNDED,
        self::STATUS_PARTIALLY_REFUNDED,
        self::STATUS_FAILED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'payment', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Order $order;

    #[Assert\Choice(choices: Payment::METHODS)]
    #[ORM\Column(length: 16)]
    private string $method;

    #[Assert\Choice(choices: Payment::STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
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
    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): self { $this->method = $method; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
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
