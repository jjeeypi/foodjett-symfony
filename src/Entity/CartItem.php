<?php

namespace App\Entity;

use App\Repository\CartItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line in a customer's cart, saved in the database so the cart survives logins and devices.
 * A cart holds items from one restaurant at a time; prices are recomputed at checkout.
 */
#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\Table(name: 'cart_items')]
class CartItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'cartItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MenuItem $menuItem = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?MenuItemVariant $variant = null;

    /** @var list<string> ids of the chosen MenuItemAddon rows */
    #[ORM\Column]
    private array $addonIds = [];

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private ?int $quantity = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $specialInstructions = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getMenuItem(): ?MenuItem
    {
        return $this->menuItem;
    }

    public function setMenuItem(?MenuItem $menuItem): static
    {
        $this->menuItem = $menuItem;

        return $this;
    }

    public function getVariant(): ?MenuItemVariant
    {
        return $this->variant;
    }

    public function setVariant(?MenuItemVariant $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getAddonIds(): array
    {
        return $this->addonIds;
    }

    public function setAddonIds(array $addonIds): static
    {
        $this->addonIds = $addonIds;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getSpecialInstructions(): ?string
    {
        return $this->specialInstructions;
    }

    public function setSpecialInstructions(?string $specialInstructions): static
    {
        $this->specialInstructions = $specialInstructions;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * The add-ons picked for this line that the menu item still offers.
     *
     * @return list<MenuItemAddon>
     */
    public function getChosenAddons(): array
    {
        return array_values(array_filter(
            $this->menuItem->getAddons()->toArray(),
            fn (MenuItemAddon $addon): bool => in_array((string) $addon->getId(), $this->addonIds, true),
        ));
    }

    /** Current price of one unit: base price, variant change and add-ons. Checkout recomputes it. */
    public function getUnitPrice(): string
    {
        $price = bcadd($this->menuItem->getBasePrice(), $this->variant?->getPriceDelta() ?? '0', 2);
        foreach ($this->getChosenAddons() as $addon) {
            $price = bcadd($price, $addon->getPrice(), 2);
        }

        return $price;
    }

    public function getLineTotal(): string
    {
        return bcmul($this->getUnitPrice(), (string) $this->quantity, 2);
    }

    /** Two lines with the same item, variant, add-ons and instructions are merged into one. */
    public function isSameChoiceAs(MenuItem $item, ?MenuItemVariant $variant, array $addonIds, ?string $instructions): bool
    {
        $mine = $this->addonIds;
        sort($mine);
        sort($addonIds);

        return $this->menuItem === $item && $this->variant === $variant && $mine === $addonIds
            && ($this->specialInstructions ?? '') === ($instructions ?? '');
    }
}
