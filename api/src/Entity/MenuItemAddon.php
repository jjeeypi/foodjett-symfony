<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'menu_item_addons')]
#[ORM\Index(name: 'menu_item_addons_menu_item_id_foreign', columns: ['menu_item_id'])]
class MenuItemAddon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'addons', targetEntity: MenuItem::class)]
    #[ORM\JoinColumn(name: 'menu_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private MenuItem $menuItem;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isAvailable = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, OrderItemAddon> */
    #[ORM\OneToMany(mappedBy: 'menuItemAddon', targetEntity: OrderItemAddon::class, cascade: ['persist'])]
    private Collection $orderItemAddons;

    public function __construct()
    {
        $this->orderItemAddons = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getMenuItem(): MenuItem
    {
        return $this->menuItem;
    }

    public function setMenuItem(MenuItem $menuItem): self
    {
        $this->menuItem = $menuItem;

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

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

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

    /** @return Collection<int, OrderItemAddon> */
    public function getOrderItemAddons(): Collection
    {
        return $this->orderItemAddons;
    }
}
