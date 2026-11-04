<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VoucherRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: VoucherRepository::class)]
#[ORM\Table(name: 'vouchers')]
#[ORM\UniqueConstraint(name: 'vouchers_code_unique', columns: ['code'])]
#[ORM\Index(name: 'vouchers_restaurant_id_foreign', columns: ['restaurant_id'])]
#[UniqueEntity(fields: ['code'], message: 'A voucher with that code already exists.')]
class Voucher
{
    // Who funds the voucher (SCOPES)
    public const SCOPE_PLATFORM = 'platform';
    public const SCOPE_RESTAURANT = 'restaurant';

    /** @var list<string> */
    public const SCOPES = [
        self::SCOPE_PLATFORM,
        self::SCOPE_RESTAURANT,
    ];

    // How the discount is computed (TYPES)
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_FREE_DELIVERY = 'free_delivery';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED,
        self::TYPE_FREE_DELIVERY,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[Assert\NotBlank(message: 'A voucher code is required.')]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^[A-Z0-9_-]+$/', message: 'Use letters, digits, dashes or underscores only.')]
    #[ORM\Column(length: 255, unique: true)]
    private string $code;

    #[Assert\Choice(choices: Voucher::SCOPES)]
    #[ORM\Column(length: 16)]
    private string $scope;

    #[ORM\ManyToOne(inversedBy: 'vouchers', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Restaurant $restaurant = null;

    #[Assert\Choice(choices: Voucher::TYPES)]
    #[ORM\Column(length: 32)]
    private string $type;

    #[Assert\Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount, like 50 or 12.50.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $value = null;

    #[Assert\Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount of zero or more, like 100 or 49.50.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $minOrderAmount = '0.00';

    #[Assert\Positive]
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $usageLimitTotal = null;

    #[Assert\Positive]
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
    public function setCode(string $code): self { $this->code = mb_strtoupper(trim($code)); return $this; }
    public function getScope(): string { return $this->scope; }
    public function setScope(string $scope): self { $this->scope = $scope; return $this; }
    public function getRestaurant(): ?Restaurant { return $this->restaurant; }
    public function setRestaurant(?Restaurant $restaurant): self { $this->restaurant = $restaurant; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
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

    /** Percentage and fixed vouchers need a value above zero (at most 100 for percentages); free delivery needs none. */
    #[Assert\Callback]
    public function validateValueAndDates(ExecutionContextInterface $context): void
    {
        if (isset($this->type) && self::TYPE_FREE_DELIVERY !== $this->type) {
            if (null === $this->value || !is_numeric($this->value) || 1 !== bccomp($this->value, '0', 2)) {
                $context->buildViolation('Give a value above zero for this kind of voucher.')->atPath('value')->addViolation();
            } elseif (self::TYPE_PERCENTAGE === $this->type && 1 === bccomp($this->value, '100', 2)) {
                $context->buildViolation('A percentage cannot be more than 100.')->atPath('value')->addViolation();
            }
        }
        if (null !== $this->startsAt && null !== $this->endsAt && $this->endsAt < $this->startsAt) {
            $context->buildViolation('The end must be after the start.')->atPath('endsAt')->addViolation();
        }
    }
}
