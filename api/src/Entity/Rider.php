<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ApprovalStatus;
use App\Enum\PayoutMethod;
use App\Enum\RiderAvailabilityStatus;
use App\Enum\VehicleType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'riders')]
#[ORM\Index(name: 'riders_availability_status_approval_status_index', columns: ['availability_status', 'approval_status'])]
#[ORM\UniqueConstraint(name: 'riders_user_id_unique', columns: ['user_id'])]
class Rider
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'rider', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 32, enumType: VehicleType::class)]
    private VehicleType $vehicleType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $plateNumber = null;

    #[ORM\Column(length: 32, enumType: ApprovalStatus::class, options: ['default' => 'pending'])]
    private ApprovalStatus $approvalStatus = ApprovalStatus::PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true, columnDefinition: 'TEXT DEFAULT NULL')]
    private ?string $rejectionReason = null;

    #[ORM\Column(length: 32, enumType: RiderAvailabilityStatus::class, options: ['default' => 'offline'])]
    private RiderAvailabilityStatus $availabilityStatus = RiderAvailabilityStatus::OFFLINE;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $currentLatitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    private ?string $currentLongitude = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $lastLocationAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $cashOnHand = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '2000.00'])]
    private string $cashRemitLimit = '2000.00';

    #[ORM\Column(length: 32, nullable: true, enumType: PayoutMethod::class)]
    private ?PayoutMethod $payoutMethod = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payoutAccountDetails = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, RiderDocument> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: RiderDocument::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /** @var Collection<int, RiderPoolDecline> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: RiderPoolDecline::class, cascade: ['persist'])]
    private Collection $poolDeclines;

    /** @var Collection<int, Order> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: Order::class, cascade: ['persist'])]
    private Collection $orders;

    /** @var Collection<int, RiderEarning> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: RiderEarning::class, cascade: ['persist'])]
    private Collection $earnings;

    /** @var Collection<int, RiderPayout> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: RiderPayout::class, cascade: ['persist'])]
    private Collection $payouts;

    /** @var Collection<int, RiderCashRemittance> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: RiderCashRemittance::class, cascade: ['persist'])]
    private Collection $cashRemittances;

    /** @var Collection<int, RiderReview> */
    #[ORM\OneToMany(mappedBy: 'rider', targetEntity: RiderReview::class, cascade: ['persist'])]
    private Collection $reviews;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->poolDeclines = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->earnings = new ArrayCollection();
        $this->payouts = new ArrayCollection();
        $this->cashRemittances = new ArrayCollection();
        $this->reviews = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        if ($user->getRider() !== $this) {
            $user->setRider($this);
        }

        return $this;
    }

    public function getVehicleType(): VehicleType
    {
        return $this->vehicleType;
    }

    public function setVehicleType(VehicleType $vehicleType): self
    {
        $this->vehicleType = $vehicleType;

        return $this;
    }

    public function getPlateNumber(): ?string
    {
        return $this->plateNumber;
    }

    public function setPlateNumber(?string $plateNumber): self
    {
        $this->plateNumber = $plateNumber;

        return $this;
    }

    public function getApprovalStatus(): ApprovalStatus
    {
        return $this->approvalStatus;
    }

    public function setApprovalStatus(ApprovalStatus $approvalStatus): self
    {
        $this->approvalStatus = $approvalStatus;

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

    public function getAvailabilityStatus(): RiderAvailabilityStatus
    {
        return $this->availabilityStatus;
    }

    public function setAvailabilityStatus(RiderAvailabilityStatus $availabilityStatus): self
    {
        $this->availabilityStatus = $availabilityStatus;

        return $this;
    }

    public function getCurrentLatitude(): ?string
    {
        return $this->currentLatitude;
    }

    public function setCurrentLatitude(?string $currentLatitude): self
    {
        $this->currentLatitude = $currentLatitude;

        return $this;
    }

    public function getCurrentLongitude(): ?string
    {
        return $this->currentLongitude;
    }

    public function setCurrentLongitude(?string $currentLongitude): self
    {
        $this->currentLongitude = $currentLongitude;

        return $this;
    }

    public function getLastLocationAt(): ?\DateTimeImmutable
    {
        return $this->lastLocationAt;
    }

    public function setLastLocationAt(?\DateTimeImmutable $lastLocationAt): self
    {
        $this->lastLocationAt = $lastLocationAt;

        return $this;
    }

    public function getCashOnHand(): string
    {
        return $this->cashOnHand;
    }

    public function setCashOnHand(string $cashOnHand): self
    {
        $this->cashOnHand = $cashOnHand;

        return $this;
    }

    public function getCashRemitLimit(): string
    {
        return $this->cashRemitLimit;
    }

    public function setCashRemitLimit(string $cashRemitLimit): self
    {
        $this->cashRemitLimit = $cashRemitLimit;

        return $this;
    }

    public function getPayoutMethod(): ?PayoutMethod
    {
        return $this->payoutMethod;
    }

    public function setPayoutMethod(?PayoutMethod $payoutMethod): self
    {
        $this->payoutMethod = $payoutMethod;

        return $this;
    }

    public function getPayoutAccountDetails(): ?array
    {
        return $this->payoutAccountDetails;
    }

    public function setPayoutAccountDetails(?array $payoutAccountDetails): self
    {
        $this->payoutAccountDetails = $payoutAccountDetails;

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

    /** @return Collection<int, RiderDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(RiderDocument $document): self
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setRider($this);
        }

        return $this;
    }

    public function removeDocument(RiderDocument $document): self
    {
        $this->documents->removeElement($document);

        return $this;
    }

    /** @return Collection<int, RiderPoolDecline> */
    public function getPoolDeclines(): Collection
    {
        return $this->poolDeclines;
    }

    public function addPoolDecline(RiderPoolDecline $poolDecline): self
    {
        if (!$this->poolDeclines->contains($poolDecline)) {
            $this->poolDeclines->add($poolDecline);
            $poolDecline->setRider($this);
        }

        return $this;
    }

    /** @return Collection<int, Order> */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setRider($this);
        }

        return $this;
    }

    /** @return Collection<int, RiderEarning> */
    public function getEarnings(): Collection
    {
        return $this->earnings;
    }

    /** @return Collection<int, RiderPayout> */
    public function getPayouts(): Collection
    {
        return $this->payouts;
    }

    /** @return Collection<int, RiderCashRemittance> */
    public function getCashRemittances(): Collection
    {
        return $this->cashRemittances;
    }

    /** @return Collection<int, RiderReview> */
    public function getReviews(): Collection { return $this->reviews; }
}
