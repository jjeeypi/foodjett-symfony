<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ApprovalStatus;
use App\Enum\PayoutMethod;
use App\Enum\RestaurantOperatingStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'restaurants')]
#[ORM\Index(name: 'restaurants_approval_status_operating_status_index', columns: ['approval_status', 'operating_status'])]
#[ORM\UniqueConstraint(name: 'restaurants_slug_unique', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'restaurants_user_id_unique', columns: ['user_id'])]
class Restaurant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'restaurant', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverPhotoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cuisineType = null;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $latitude;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $longitude;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 15])]
    private int $defaultPrepTimeMinutes = 15;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $minOrderAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '15.00'])]
    private string $commissionRate = '15.00';

    #[ORM\Column(length: 32, enumType: ApprovalStatus::class, options: ['default' => 'pending'])]
    private ApprovalStatus $approvalStatus = ApprovalStatus::PENDING;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(length: 32, enumType: RestaurantOperatingStatus::class, options: ['default' => 'closed'])]
    private RestaurantOperatingStatus $operatingStatus = RestaurantOperatingStatus::CLOSED;

    #[ORM\Column(length: 32, nullable: true, enumType: PayoutMethod::class)]
    private ?PayoutMethod $payoutMethod = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payoutAccountDetails = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, RestaurantDocument> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: RestaurantDocument::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /** @var Collection<int, RestaurantOperatingHour> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: RestaurantOperatingHour::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dayOfWeek' => 'ASC'])]
    private Collection $operatingHours;

    /** @var Collection<int, MenuCategory> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: MenuCategory::class, cascade: ['persist'])]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $menuCategories;

    /** @var Collection<int, Order> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: Order::class, cascade: ['persist'])]
    private Collection $orders;

    /** @var Collection<int, RestaurantPayout> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: RestaurantPayout::class, cascade: ['persist'])]
    private Collection $payouts;

    /** @var Collection<int, Voucher> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: Voucher::class, cascade: ['persist'])]
    private Collection $vouchers;

    /** @var Collection<int, RestaurantReview> */
    #[ORM\OneToMany(mappedBy: 'restaurant', targetEntity: RestaurantReview::class, cascade: ['persist'])]
    private Collection $reviews;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
        $this->operatingHours = new ArrayCollection();
        $this->menuCategories = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->payouts = new ArrayCollection();
        $this->vouchers = new ArrayCollection();
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

        if ($user->getRestaurant() !== $this) {
            $user->setRestaurant($this);
        }

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): self
    {
        $this->logoPath = $logoPath;

        return $this;
    }

    public function getCoverPhotoPath(): ?string
    {
        return $this->coverPhotoPath;
    }

    public function setCoverPhotoPath(?string $coverPhotoPath): self
    {
        $this->coverPhotoPath = $coverPhotoPath;

        return $this;
    }

    public function getCuisineType(): ?string
    {
        return $this->cuisineType;
    }

    public function setCuisineType(?string $cuisineType): self
    {
        $this->cuisineType = $cuisineType;

        return $this;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getLatitude(): string
    {
        return $this->latitude;
    }

    public function setLatitude(string $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): string
    {
        return $this->longitude;
    }

    public function setLongitude(string $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getDefaultPrepTimeMinutes(): int
    {
        return $this->defaultPrepTimeMinutes;
    }

    public function setDefaultPrepTimeMinutes(int $defaultPrepTimeMinutes): self
    {
        $this->defaultPrepTimeMinutes = $defaultPrepTimeMinutes;

        return $this;
    }

    public function getMinOrderAmount(): string
    {
        return $this->minOrderAmount;
    }

    public function setMinOrderAmount(string $minOrderAmount): self
    {
        $this->minOrderAmount = $minOrderAmount;

        return $this;
    }

    public function getCommissionRate(): string
    {
        return $this->commissionRate;
    }

    public function setCommissionRate(string $commissionRate): self
    {
        $this->commissionRate = $commissionRate;

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

    public function getOperatingStatus(): RestaurantOperatingStatus
    {
        return $this->operatingStatus;
    }

    public function setOperatingStatus(RestaurantOperatingStatus $operatingStatus): self
    {
        $this->operatingStatus = $operatingStatus;

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

    /** @return Collection<int, RestaurantDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(RestaurantDocument $document): self
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setRestaurant($this);
        }

        return $this;
    }

    public function removeDocument(RestaurantDocument $document): self
    {
        $this->documents->removeElement($document);

        return $this;
    }

    /** @return Collection<int, RestaurantOperatingHour> */
    public function getOperatingHours(): Collection
    {
        return $this->operatingHours;
    }

    public function addOperatingHour(RestaurantOperatingHour $operatingHour): self
    {
        if (!$this->operatingHours->contains($operatingHour)) {
            $this->operatingHours->add($operatingHour);
            $operatingHour->setRestaurant($this);
        }

        return $this;
    }

    public function removeOperatingHour(RestaurantOperatingHour $operatingHour): self
    {
        $this->operatingHours->removeElement($operatingHour);

        return $this;
    }

    /** @return Collection<int, MenuCategory> */
    public function getMenuCategories(): Collection
    {
        return $this->menuCategories;
    }

    public function addMenuCategory(MenuCategory $menuCategory): self
    {
        if (!$this->menuCategories->contains($menuCategory)) {
            $this->menuCategories->add($menuCategory);
            $menuCategory->setRestaurant($this);
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
            $order->setRestaurant($this);
        }

        return $this;
    }

    /** @return Collection<int, RestaurantPayout> */
    public function getPayouts(): Collection
    {
        return $this->payouts;
    }

    /** @return Collection<int, Voucher> */
    public function getVouchers(): Collection
    {
        return $this->vouchers;
    }

    /** @return Collection<int, RestaurantReview> */
    public function getReviews(): Collection { return $this->reviews; }
}
