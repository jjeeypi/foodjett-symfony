<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'menu_items',
    indexes: [
        new ORM\Index(name: 'menu_items_is_available_index', columns: ['is_available']),
    ],
)]
class MenuItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'items', targetEntity: MenuCategory::class)]
    #[ORM\JoinColumn(name: 'menu_category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private MenuCategory $menuCategory;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $basePrice;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isAvailable = true;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availableFrom = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availableUntil = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isFeatured = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, MenuItemVariant> */
    #[ORM\OneToMany(mappedBy: 'menuItem', targetEntity: MenuItemVariant::class, cascade: ['persist'])]
    private Collection $variants;

    /** @var Collection<int, MenuItemAddon> */
    #[ORM\OneToMany(mappedBy: 'menuItem', targetEntity: MenuItemAddon::class, cascade: ['persist'])]
    private Collection $addons;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(mappedBy: 'menuItem', targetEntity: OrderItem::class, cascade: ['persist'])]
    private Collection $orderItems;

    public function __construct()
    {
        $this->variants = new ArrayCollection();
        $this->addons = new ArrayCollection();
        $this->orderItems = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getMenuCategory(): MenuCategory
    {
        return $this->menuCategory;
    }

    public function setMenuCategory(MenuCategory $menuCategory): self
    {
        $this->menuCategory = $menuCategory;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): self
    {
        $this->photoPath = $photoPath;

        return $this;
    }

    public function getBasePrice(): string
    {
        return $this->basePrice;
    }

    public function setBasePrice(string $basePrice): self
    {
        $this->basePrice = $basePrice;

        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function setIsAvailable(bool $isAvailable): self
    {
        $this->isAvailable = $isAvailable;

        return $this;
    }

    public function getAvailableFrom(): ?\DateTimeImmutable
    {
        return $this->availableFrom;
    }

    public function setAvailableFrom(?\DateTimeImmutable $availableFrom): self
    {
        $this->availableFrom = $availableFrom;

        return $this;
    }

    public function getAvailableUntil(): ?\DateTimeImmutable
    {
        return $this->availableUntil;
    }

    public function setAvailableUntil(?\DateTimeImmutable $availableUntil): self
    {
        $this->availableUntil = $availableUntil;

        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): self
    {
        $this->isFeatured = $isFeatured;

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

    /** @return Collection<int, MenuItemVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(MenuItemVariant $variant): self
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setMenuItem($this);
        }

        return $this;
    }

    /** @return Collection<int, MenuItemAddon> */
    public function getAddons(): Collection
    {
        return $this->addons;
    }

    public function addAddon(MenuItemAddon $addon): self
    {
        if (!$this->addons->contains($addon)) {
            $this->addons->add($addon);
            $addon->setMenuItem($this);
        }

        return $this;
    }

    /** @return Collection<int, OrderItem> */
    public function getOrderItems(): Collection
    {
        return $this->orderItems;
    }
}
