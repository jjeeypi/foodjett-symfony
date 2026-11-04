<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiderDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RiderDocumentRepository::class)]
#[ORM\Table(name: 'rider_documents')]
#[ORM\UniqueConstraint(name: 'rider_documents_rider_type_unique', columns: ['rider_id', 'type'])]
#[ORM\Index(name: 'rider_documents_rider_id_foreign', columns: ['rider_id'])]
class RiderDocument
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

    // Required rider document (TYPES)
    public const TYPE_DRIVERS_LICENSE = 'drivers_license';
    public const TYPE_VEHICLE_REGISTRATION = 'vehicle_registration';
    public const TYPE_VALID_ID = 'valid_id';
    public const TYPE_POLICE_CLEARANCE = 'police_clearance';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_DRIVERS_LICENSE,
        self::TYPE_VEHICLE_REGISTRATION,
        self::TYPE_VALID_ID,
        self::TYPE_POLICE_CLEARANCE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'documents', targetEntity: Rider::class)]
    #[ORM\JoinColumn(name: 'rider_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Rider $rider;

    #[Assert\Choice(choices: RiderDocument::TYPES)]
    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $filePath;

    #[Assert\Choice(choices: RiderDocument::STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'pending'])]
    private string $status = RestaurantDocument::STATUS_PENDING;

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

    public function getRider(): Rider
    {
        return $this->rider;
    }

    public function setRider(Rider $rider): self
    {
        $this->rider = $rider;

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
