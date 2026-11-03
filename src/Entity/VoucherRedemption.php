<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'voucher_redemptions')]
#[ORM\UniqueConstraint(name: 'voucher_redemptions_order_id_unique', columns: ['order_id'])]
#[ORM\Index(name: 'voucher_redemptions_voucher_id_foreign', columns: ['voucher_id'])]
#[ORM\Index(name: 'voucher_redemptions_customer_id_foreign', columns: ['customer_id'])]
class VoucherRedemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'redemptions', targetEntity: Voucher::class)]
    #[ORM\JoinColumn(name: 'voucher_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Voucher $voucher;

    #[ORM\OneToOne(inversedBy: 'voucherRedemption', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\ManyToOne(inversedBy: 'voucherRedemptions', targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $discountApplied;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getVoucher(): Voucher { return $this->voucher; }
    public function setVoucher(Voucher $voucher): self { $this->voucher = $voucher; return $this; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $customer): self { $this->customer = $customer; return $this; }
    public function getDiscountApplied(): string { return $this->discountApplied; }
    public function setDiscountApplied(string $amount): self { $this->discountApplied = $amount; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $at): self { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $at): self { $this->updatedAt = $at; return $this; }
}
