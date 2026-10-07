<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentActor;
use App\Enum\PaymentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_status_history')]
class PaymentStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'statusHistory', targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    #[ORM\Column(length: 32, nullable: true, enumType: PaymentStatus::class)]
    private ?PaymentStatus $fromStatus = null;

    #[ORM\Column(length: 32, enumType: PaymentStatus::class)]
    private PaymentStatus $toStatus;

    #[ORM\Column(length: 16, enumType: PaymentActor::class)]
    private PaymentActor $changedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, columnDefinition: 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?string { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function setPayment(Payment $payment): self { $this->payment = $payment; return $this; }
    public function getFromStatus(): ?PaymentStatus { return $this->fromStatus; }
    public function setFromStatus(?PaymentStatus $status): self { $this->fromStatus = $status; return $this; }
    public function getToStatus(): PaymentStatus { return $this->toStatus; }
    public function setToStatus(PaymentStatus $status): self { $this->toStatus = $status; return $this; }
    public function getChangedBy(): PaymentActor { return $this->changedBy; }
    public function setChangedBy(PaymentActor $actor): self { $this->changedBy = $actor; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
}
