<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PayoutStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'restaurant_payouts',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'restaurant_payout_period_unique', columns: ['restaurant_id', 'period_start', 'period_end']),
    ],
)]
class RestaurantPayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'payouts', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Restaurant $restaurant;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $grossSales;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $commissionDeducted;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $netAmount;

    #[ORM\Column(length: 16, enumType: PayoutStatus::class, options: ['default' => 'pending'])]
    private PayoutStatus $status = PayoutStatus::PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getRestaurant(): Restaurant { return $this->restaurant; }
    public function setRestaurant(Restaurant $restaurant): self { $this->restaurant = $restaurant; return $this; }
    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $date): self { $this->periodStart = $date; return $this; }
    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $date): self { $this->periodEnd = $date; return $this; }
    public function getGrossSales(): string { return $this->grossSales; }
    public function setGrossSales(string $amount): self { $this->grossSales = $amount; return $this; }
    public function getCommissionDeducted(): string { return $this->commissionDeducted; }
    public function setCommissionDeducted(string $amount): self { $this->commissionDeducted = $amount; return $this; }
    public function getNetAmount(): string { return $this->netAmount; }
    public function setNetAmount(string $amount): self { $this->netAmount = $amount; return $this; }
    public function getStatus(): PayoutStatus { return $this->status; }
    public function setStatus(PayoutStatus $status): self { $this->status = $status; return $this; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): self { $this->paidAt = $paidAt; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
