<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rider_earnings')]
#[ORM\UniqueConstraint(name: 'rider_earnings_order_id_unique', columns: ['order_id'])]
#[ORM\Index(name: 'rider_earnings_rider_id_foreign', columns: ['rider_id'])]
class RiderEarning
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'earnings', targetEntity: Rider::class)]
    #[ORM\JoinColumn(name: 'rider_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Rider $rider;

    #[ORM\OneToOne(inversedBy: 'riderEarning', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $basePay;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $distancePay;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, options: ['default' => '0.00'])]
    private string $waitingPay = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, options: ['default' => '0.00'])]
    private string $incentivePay = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, options: ['default' => '0.00'])]
    private string $tipAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $totalEarned;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getRider(): Rider { return $this->rider; }
    public function setRider(Rider $rider): self { $this->rider = $rider; return $this; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getBasePay(): string { return $this->basePay; }
    public function setBasePay(string $amount): self { $this->basePay = $amount; return $this; }
    public function getDistancePay(): string { return $this->distancePay; }
    public function setDistancePay(string $amount): self { $this->distancePay = $amount; return $this; }
    public function getWaitingPay(): string { return $this->waitingPay; }
    public function setWaitingPay(string $amount): self { $this->waitingPay = $amount; return $this; }
    public function getIncentivePay(): string { return $this->incentivePay; }
    public function setIncentivePay(string $amount): self { $this->incentivePay = $amount; return $this; }
    public function getTipAmount(): string { return $this->tipAmount; }
    public function setTipAmount(string $amount): self { $this->tipAmount = $amount; return $this; }
    public function getTotalEarned(): string { return $this->totalEarned; }
    public function setTotalEarned(string $amount): self { $this->totalEarned = $amount; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
