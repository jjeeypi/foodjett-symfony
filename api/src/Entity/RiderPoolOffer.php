<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RiderPoolEscalationStage;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rider_pool_offers')]
class RiderPoolOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'riderPoolOffer', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, options: ['default' => '3.0'])]
    private string $searchRadiusKm = '3.0';

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, options: ['default' => '0.00'])]
    private string $incentiveAmount = '0.00';

    #[ORM\Column(length: 32, enumType: RiderPoolEscalationStage::class, options: ['default' => 'initial'])]
    private RiderPoolEscalationStage $escalationStage = RiderPoolEscalationStage::INITIAL;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $adminAssigned = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getSearchRadiusKm(): string
    {
        return $this->searchRadiusKm;
    }

    public function setSearchRadiusKm(string $searchRadiusKm): self
    {
        $this->searchRadiusKm = $searchRadiusKm;

        return $this;
    }

    public function getIncentiveAmount(): string
    {
        return $this->incentiveAmount;
    }

    public function setIncentiveAmount(string $incentiveAmount): self
    {
        $this->incentiveAmount = $incentiveAmount;

        return $this;
    }

    public function getEscalationStage(): RiderPoolEscalationStage
    {
        return $this->escalationStage;
    }

    public function setEscalationStage(RiderPoolEscalationStage $escalationStage): self
    {
        $this->escalationStage = $escalationStage;

        return $this;
    }

    public function isAdminAssigned(): bool
    {
        return $this->adminAssigned;
    }

    public function setAdminAssigned(bool $adminAssigned): self
    {
        $this->adminAssigned = $adminAssigned;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
