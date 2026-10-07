<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'items', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\ManyToOne(inversedBy: 'orderItems', targetEntity: MenuItem::class)]
    #[ORM\JoinColumn(name: 'menu_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private MenuItem $menuItem;

    #[ORM\ManyToOne(inversedBy: 'orderItems', targetEntity: MenuItemVariant::class)]
    #[ORM\JoinColumn(name: 'menu_item_variant_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?MenuItemVariant $menuItemVariant = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $specialInstructions = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, OrderItemAddon> */
    #[ORM\OneToMany(mappedBy: 'orderItem', targetEntity: OrderItemAddon::class, cascade: ['persist'])]
    private Collection $addons;

    public function __construct()
    {
        $this->addons = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getMenuItem(): MenuItem { return $this->menuItem; }
    public function setMenuItem(MenuItem $menuItem): self { $this->menuItem = $menuItem; return $this; }
    public function getMenuItemVariant(): ?MenuItemVariant { return $this->menuItemVariant; }
    public function setMenuItemVariant(?MenuItemVariant $variant): self { $this->menuItemVariant = $variant; return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }
    public function getUnitPrice(): string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): self { $this->unitPrice = $unitPrice; return $this; }
    public function getSpecialInstructions(): ?string { return $this->specialInstructions; }
    public function setSpecialInstructions(?string $value): self { $this->specialInstructions = $value; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
    /** @return Collection<int, OrderItemAddon> */
    public function getAddons(): Collection { return $this->addons; }
    public function addAddon(OrderItemAddon $addon): self { if (!$this->addons->contains($addon)) { $this->addons->add($addon); $addon->setOrderItem($this); } return $this; }
}
