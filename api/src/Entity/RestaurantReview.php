<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'restaurant_reviews')]
#[ORM\UniqueConstraint(name: 'restaurant_reviews_order_id_unique', columns: ['order_id'])]
#[ORM\Index(name: 'restaurant_reviews_customer_id_foreign', columns: ['customer_id'])]
#[ORM\Index(name: 'restaurant_reviews_restaurant_id_foreign', columns: ['restaurant_id'])]
class RestaurantReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'restaurantReview', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\ManyToOne(inversedBy: 'restaurantReviews', targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\ManyToOne(inversedBy: 'reviews', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Restaurant $restaurant;

    #[ORM\Column(type: Types::SMALLINT, columnDefinition: 'TINYINT UNSIGNED NOT NULL')]
    private int $rating;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $restaurantReply = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $repliedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $customer): self { $this->customer = $customer; return $this; }
    public function getRestaurant(): Restaurant { return $this->restaurant; }
    public function setRestaurant(Restaurant $restaurant): self { $this->restaurant = $restaurant; return $this; }
    public function getRating(): int { return $this->rating; }
    public function setRating(int $rating): self { if ($rating < 1 || $rating > 5) { throw new \InvalidArgumentException('Rating must be between 1 and 5.'); } $this->rating = $rating; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }
    public function getPhotoPath(): ?string { return $this->photoPath; }
    public function setPhotoPath(?string $path): self { $this->photoPath = $path; return $this; }
    public function getRestaurantReply(): ?string { return $this->restaurantReply; }
    public function setRestaurantReply(?string $reply): self { $this->restaurantReply = $reply; return $this; }
    public function getRepliedAt(): ?\DateTimeImmutable { return $this->repliedAt; }
    public function setRepliedAt(?\DateTimeImmutable $at): self { $this->repliedAt = $at; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $at): self { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $at): self { $this->updatedAt = $at; return $this; }
}
