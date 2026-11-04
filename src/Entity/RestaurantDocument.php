<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RestaurantDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RestaurantDocumentRepository::class)]
#[ORM\Table(name: 'restaurant_documents')]
#[ORM\UniqueConstraint(name: 'restaurant_documents_restaurant_type_unique', columns: ['restaurant_id', 'type'])]
#[ORM\Index(name: 'restaurant_documents_restaurant_id_foreign', columns: ['restaurant_id'])]
class RestaurantDocument
{
    // Review status of an uploaded document (STATUSES)
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
    ];

    // Required restaurant document (TYPES)
    public const TYPE_BUSINESS_PERMIT = 'business_permit';
    public const TYPE_FOOD_SAFETY_PERMIT = 'food_safety_permit';
    public const TYPE_OWNER_ID = 'owner_id';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_BUSINESS_PERMIT,
        self::TYPE_FOOD_SAFETY_PERMIT,
        self::TYPE_OWNER_ID,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'documents', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Restaurant $restaurant;

    #[Assert\Choice(choices: RestaurantDocument::TYPES)]
    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $filePath;

    #[Assert\Choice(choices: RestaurantDocument::STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getRestaurant(): Restaurant
    {
        return $this->restaurant;
    }

    public function setRestaurant(Restaurant $restaurant): self
    {
        $this->restaurant = $restaurant;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;

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
