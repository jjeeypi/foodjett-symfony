<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
#[ORM\UniqueConstraint(name: 'customers_user_id_unique', columns: ['user_id'])]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'customer', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, CustomerAddress> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: CustomerAddress::class, cascade: ['persist'])]
    private Collection $addresses;

    /** @var Collection<int, Order> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: Order::class, cascade: ['persist'])]
    private Collection $orders;

    /** @var Collection<int, VoucherRedemption> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: VoucherRedemption::class, cascade: ['persist'])]
    private Collection $voucherRedemptions;

    /** @var Collection<int, RestaurantReview> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: RestaurantReview::class, cascade: ['persist'])]
    private Collection $restaurantReviews;

    /** @var Collection<int, RiderReview> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: RiderReview::class, cascade: ['persist'])]
    private Collection $riderReviews;

    public function __construct()
    {
        $this->addresses = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->voucherRedemptions = new ArrayCollection();
        $this->restaurantReviews = new ArrayCollection();
        $this->riderReviews = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        if ($user->getCustomer() !== $this) {
            $user->setCustomer($this);
        }

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

    /** @return Collection<int, CustomerAddress> */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function addAddress(CustomerAddress $address): self
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
            $address->setCustomer($this);
        }

        return $this;
    }

    /** @return Collection<int, Order> */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setCustomer($this);
        }

        return $this;
    }

    /** @return Collection<int, VoucherRedemption> */
    public function getVoucherRedemptions(): Collection
    {
        return $this->voucherRedemptions;
    }

    /** @return Collection<int, RestaurantReview> */
    public function getRestaurantReviews(): Collection { return $this->restaurantReviews; }

    /** @return Collection<int, RiderReview> */
    public function getRiderReviews(): Collection { return $this->riderReviews; }
}
