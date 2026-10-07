<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_item_addons')]
#[ORM\Index(name: 'order_item_addons_order_item_id_foreign', columns: ['order_item_id'])]
#[ORM\Index(name: 'order_item_addons_menu_item_addon_id_foreign', columns: ['menu_item_addon_id'])]
class OrderItemAddon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'addons', targetEntity: OrderItem::class)]
    #[ORM\JoinColumn(name: 'order_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private OrderItem $orderItem;

    #[ORM\ManyToOne(inversedBy: 'orderItemAddons', targetEntity: MenuItemAddon::class)]
    #[ORM\JoinColumn(name: 'menu_item_addon_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private MenuItemAddon $menuItemAddon;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getOrderItem(): OrderItem { return $this->orderItem; }
    public function setOrderItem(OrderItem $orderItem): self { $this->orderItem = $orderItem; return $this; }
    public function getMenuItemAddon(): MenuItemAddon { return $this->menuItemAddon; }
    public function setMenuItemAddon(MenuItemAddon $addon): self { $this->menuItemAddon = $addon; return $this; }
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): self { $this->price = $price; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
