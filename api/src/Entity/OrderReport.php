<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderReportAgainst;
use App\Enum\OrderReportStatus;
use App\Enum\OrderReportType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_reports')]
#[ORM\Index(name: 'order_reports_order_id_foreign', columns: ['order_id'])]
#[ORM\Index(name: 'order_reports_reported_by_user_id_foreign', columns: ['reported_by_user_id'])]
#[ORM\Index(name: 'order_reports_resolved_by_admin_id_foreign', columns: ['resolved_by_admin_id'])]
class OrderReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'reports', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\ManyToOne(inversedBy: 'reportedOrderReports', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reported_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $reportedBy;

    #[ORM\Column(length: 16, enumType: OrderReportAgainst::class)]
    private OrderReportAgainst $against;

    #[ORM\Column(length: 32, enumType: OrderReportType::class)]
    private OrderReportType $type;

    #[ORM\Column(type: Types::TEXT, columnDefinition: 'TEXT NOT NULL')]
    private string $description;

    #[ORM\Column(length: 32, enumType: OrderReportStatus::class, options: ['default' => 'open'])]
    private OrderReportStatus $status = OrderReportStatus::OPEN;

    #[ORM\Column(type: Types::TEXT, nullable: true, columnDefinition: 'TEXT DEFAULT NULL')]
    private ?string $resolution = null;

    #[ORM\ManyToOne(inversedBy: 'resolvedOrderReports', targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: 'resolved_by_admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Admin $resolvedByAdmin = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getReportedBy(): User { return $this->reportedBy; }
    public function setReportedBy(User $user): self { $this->reportedBy = $user; return $this; }
    public function getAgainst(): OrderReportAgainst { return $this->against; }
    public function setAgainst(OrderReportAgainst $against): self { $this->against = $against; return $this; }
    public function getType(): OrderReportType { return $this->type; }
    public function setType(OrderReportType $type): self { $this->type = $type; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getStatus(): OrderReportStatus { return $this->status; }
    public function setStatus(OrderReportStatus $status): self { $this->status = $status; return $this; }
    public function getResolution(): ?string { return $this->resolution; }
    public function setResolution(?string $resolution): self { $this->resolution = $resolution; return $this; }
    public function getResolvedByAdmin(): ?Admin { return $this->resolvedByAdmin; }
    public function setResolvedByAdmin(?Admin $admin): self { $this->resolvedByAdmin = $admin; return $this; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $at): self { $this->resolvedAt = $at; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $at): self { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $at): self { $this->updatedAt = $at; return $this; }
}
