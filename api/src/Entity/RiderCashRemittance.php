<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RemittanceStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rider_cash_remittances')]
#[ORM\Index(name: 'rider_cash_remittances_rider_id_foreign', columns: ['rider_id'])]
#[ORM\Index(name: 'rider_cash_remittances_confirmed_by_admin_id_foreign', columns: ['confirmed_by_admin_id'])]
class RiderCashRemittance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'cashRemittances', targetEntity: Rider::class)]
    #[ORM\JoinColumn(name: 'rider_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Rider $rider;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenceNote = null;

    #[ORM\Column(length: 16, enumType: RemittanceStatus::class, options: ['default' => 'pending'])]
    private RemittanceStatus $status = RemittanceStatus::PENDING;

    #[ORM\ManyToOne(inversedBy: 'confirmedCashRemittances', targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: 'confirmed_by_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Admin $confirmedByAdmin = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $remittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getRider(): Rider { return $this->rider; }
    public function setRider(Rider $rider): self { $this->rider = $rider; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getReferenceNote(): ?string { return $this->referenceNote; }
    public function setReferenceNote(?string $note): self { $this->referenceNote = $note; return $this; }
    public function getStatus(): RemittanceStatus { return $this->status; }
    public function setStatus(RemittanceStatus $status): self { $this->status = $status; return $this; }
    public function getConfirmedByAdmin(): ?Admin { return $this->confirmedByAdmin; }
    public function setConfirmedByAdmin(?Admin $admin): self { $this->confirmedByAdmin = $admin; return $this; }
    public function getRemittedAt(): ?\DateTimeImmutable { return $this->remittedAt; }
    public function setRemittedAt(?\DateTimeImmutable $at): self { $this->remittedAt = $at; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
