<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderReportRepository::class)]
#[ORM\Table(name: 'order_reports')]
#[ORM\Index(name: 'order_reports_order_id_foreign', columns: ['order_id'])]
#[ORM\Index(name: 'order_reports_reported_by_user_id_foreign', columns: ['reported_by_user_id'])]
#[ORM\Index(name: 'order_reports_resolved_by_admin_id_foreign', columns: ['resolved_by_admin_id'])]
class OrderReport
{
    // Who the report is about (AGAINST_TARGETS)
    public const AGAINST_RESTAURANT = 'restaurant';
    public const AGAINST_RIDER = 'rider';
    public const AGAINST_CUSTOMER = 'customer';
    public const AGAINST_PLATFORM = 'platform';

    /** @var list<string> */
    public const AGAINST_TARGETS = [
        self::AGAINST_RESTAURANT,
        self::AGAINST_RIDER,
        self::AGAINST_CUSTOMER,
        self::AGAINST_PLATFORM,
    ];

    // Report handling status (STATUSES)
    public const STATUS_OPEN = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
    ];

    // Kind of problem reported (TYPES)
    public const TYPE_MISSING_ITEM = 'missing_item';
    public const TYPE_WRONG_ITEM = 'wrong_item';
    public const TYPE_LATE_DELIVERY = 'late_delivery';
    public const TYPE_NO_SHOW_RIDER = 'no_show_rider';
    public const TYPE_RUDE_BEHAVIOR = 'rude_behavior';
    public const TYPE_CUSTOMER_UNREACHABLE = 'customer_unreachable';
    public const TYPE_ACCIDENT = 'accident';
    public const TYPE_OTHER = 'other';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_MISSING_ITEM,
        self::TYPE_WRONG_ITEM,
        self::TYPE_LATE_DELIVERY,
        self::TYPE_NO_SHOW_RIDER,
        self::TYPE_RUDE_BEHAVIOR,
        self::TYPE_CUSTOMER_UNREACHABLE,
        self::TYPE_ACCIDENT,
        self::TYPE_OTHER,
    ];

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

    #[Assert\Choice(choices: OrderReport::AGAINST_TARGETS)]
    #[ORM\Column(length: 16)]
    private string $against;

    #[Assert\Choice(choices: OrderReport::TYPES)]
    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(type: Types::TEXT, length: 65535)]
    private string $description;

    #[Assert\Choice(choices: OrderReport::STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'open'])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
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
    public function getAgainst(): string { return $this->against; }
    public function setAgainst(string $against): self { $this->against = $against; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
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
