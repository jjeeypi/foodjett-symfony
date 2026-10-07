<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentStatus;
use App\Enum\RestaurantDocumentType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'restaurant_documents')]
#[ORM\UniqueConstraint(name: 'restaurant_documents_restaurant_type_unique', columns: ['restaurant_id', 'type'])]
#[ORM\Index(name: 'restaurant_documents_restaurant_id_foreign', columns: ['restaurant_id'])]
class RestaurantDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'documents', targetEntity: Restaurant::class)]
    #[ORM\JoinColumn(name: 'restaurant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Restaurant $restaurant;

    #[ORM\Column(length: 32, enumType: RestaurantDocumentType::class)]
    private RestaurantDocumentType $type;

    #[ORM\Column(length: 255)]
    private string $filePath;

    #[ORM\Column(length: 32, enumType: DocumentStatus::class, options: ['default' => 'pending'])]
    private DocumentStatus $status = DocumentStatus::PENDING;

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

    public function getType(): RestaurantDocumentType
    {
        return $this->type;
    }

    public function setType(RestaurantDocumentType $type): self
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

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function setStatus(DocumentStatus $status): self
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
