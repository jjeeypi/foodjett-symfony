<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VoucherScope;
use App\Enum\VoucherType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'vouchers')]
class Voucher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $code;

    #[ORM\Column(length: 16, enumType: VoucherScope::class)]
    private VoucherScope $scope;

    #[ORM\ManyToOne(inversedBy: 'vouchers', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Restaurant $restaurant = null;

    #[ORM\Column(length: 32, enumType: VoucherType::class)]
    private VoucherType $type;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $minOrderAmount = '0.00';

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $usageLimitTotal = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $usageLimitPerCustomer = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, VoucherRedemption> */
    #[ORM\OneToMany(mappedBy: 'voucher', targetEntity: VoucherRedemption::class, cascade: ['persist'])]
    private Collection $redemptions;

    public function __construct()
    {
        $this->redemptions = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getScope(): VoucherScope { return $this->scope; }
    public function setScope(VoucherScope $scope): self { $this->scope = $scope; return $this; }
    public function getRestaurant(): ?Restaurant { return $this->restaurant; }
    public function setRestaurant(?Restaurant $restaurant): self { $this->restaurant = $restaurant; return $this; }
    public function getType(): VoucherType { return $this->type; }
    public function setType(VoucherType $type): self { $this->type = $type; return $this; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): self { $this->value = $value; return $this; }
    public function getMinOrderAmount(): string { return $this->minOrderAmount; }
    public function setMinOrderAmount(string $amount): self { $this->minOrderAmount = $amount; return $this; }
    public function getUsageLimitTotal(): ?int { return $this->usageLimitTotal; }
    public function setUsageLimitTotal(?int $limit): self { $this->usageLimitTotal = $limit; return $this; }
    public function getUsageLimitPerCustomer(): int { return $this->usageLimitPerCustomer; }
    public function setUsageLimitPerCustomer(int $limit): self { $this->usageLimitPerCustomer = $limit; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $at): self { $this->startsAt = $at; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $at): self { $this->endsAt = $at; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): self { $this->isActive = $active; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $at): self { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $at): self { $this->updatedAt = $at; return $this; }
    /** @return Collection<int, VoucherRedemption> */
    public function getRedemptions(): Collection { return $this->redemptions; }
}
