<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentStatusHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PaymentStatusHistoryRepository::class)]
#[ORM\Table(name: 'payment_status_history')]
#[ORM\Index(name: 'payment_status_history_payment_id_foreign', columns: ['payment_id'])]
class PaymentStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'statusHistory', targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    #[Assert\Choice(choices: Payment::STATUSES)]
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $fromStatus = null;

    #[Assert\Choice(choices: Payment::STATUSES)]
    #[ORM\Column(length: 32)]
    private string $toStatus;

    #[Assert\Choice(choices: Payment::ACTORS)]
    #[ORM\Column(length: 16)]
    private string $changedBy;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, columnDefinition: 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?string { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function setPayment(Payment $payment): self { $this->payment = $payment; return $this; }
    public function getFromStatus(): ?string { return $this->fromStatus; }
    public function setFromStatus(?string $status): self { $this->fromStatus = $status; return $this; }
    public function getToStatus(): string { return $this->toStatus; }
    public function setToStatus(string $status): self { $this->toStatus = $status; return $this; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function setChangedBy(string $actor): self { $this->changedBy = $actor; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
}
