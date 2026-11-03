<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'audit_logs_subject_type_subject_id_index', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'audit_logs_user_id_foreign', columns: ['user_id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'auditLogs', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $action;

    #[ORM\Column(length: 255)]
    private string $subjectType;

    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private string $subjectId;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, columnDefinition: 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function setSubjectType(string $subjectType): self { $this->subjectType = $subjectType; return $this; }
    public function getSubjectId(): string { return $this->subjectId; }
    public function setSubjectId(string $subjectId): self { $this->subjectId = $subjectId; return $this; }
    /** @return array<string, mixed>|null */
    public function getChanges(): ?array { return $this->changes; }
    /** @param array<string, mixed>|null $changes */
    public function setChanges(?array $changes): self { $this->changes = $changes; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
}
