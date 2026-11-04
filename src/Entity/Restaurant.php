<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RestaurantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RestaurantRepository::class)]
#[ORM\Table(name: 'restaurants')]
#[ORM\Index(name: 'restaurants_approval_status_operating_status_index', columns: ['approval_status', 'operating_status'])]
#[ORM\UniqueConstraint(name: 'restaurants_slug_unique', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'restaurants_user_id_unique', columns: ['user_id'])]
class Restaurant
{
    // Admin approval of the account (APPROVAL_STATUSES)
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    /** @var list<string> */
    public const APPROVAL_STATUSES = [
        self::APPROVAL_PENDING,
        self::APPROVAL_APPROVED,
        self::APPROVAL_REJECTED,
    ];

    // Where payouts are sent (PAYOUT_METHODS)
    public const PAYOUT_METHOD_BANK = 'bank';
    public const PAYOUT_METHOD_EWALLET = 'ewallet';

    /** @var list<string> */
    public const PAYOUT_METHODS = [
        self::PAYOUT_METHOD_BANK,
        self::PAYOUT_METHOD_EWALLET,
    ];

    // Whether the restaurant takes orders now (OPERATING_STATUSES)
    public const OPERATING_OPEN = 'open';
    public const OPERATING_CLOSED = 'closed';
    public const OPERATING_TEMPORARILY_CLOSED = 'temporarily_closed';

    /** @var list<string> */
    public const OPERATING_STATUSES = [
        self::OPERATING_OPEN,
        self::OPERATING_CLOSED,
        self::OPERATING_TEMPORARILY_CLOSED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\OneToOne(inversedBy: 'restaurant', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private User $user;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[Assert\Length(max: 5000)]
    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverPhotoPath = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cuisineType = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $address;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^-?(?:90(?:\.0+)?|[0-8]?\d(?:\.\d+)?)$/', message: 'Latitude must be between -90 and 90.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $latitude;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^-?(?:180(?:\.0+)?|(?:1[0-7]\d|[0-9]?\d)(?:\.\d+)?)$/', message: 'Longitude must be between -180 and 180.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $longitude;

    #[Assert\Range(min: 1, max: 240, notInRangeMessage: 'Preparation time must be between 1 and 240 minutes.')]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 15])]
    private int $defaultPrepTimeMinutes = 15;

    #[Assert\Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount of zero or more, like 150 or 99.50.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $minOrderAmount = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '15.00'])]
    private string $commissionRate = '15.00';

    #[Assert\Choice(choices: Restaurant::APPROVAL_STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'pending'])]
    private string $approvalStatus = self::APPROVAL_PENDING;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $rejectionReason = null;

    #[Assert\Choice(choices: Restaurant::OPERATING_STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'closed'])]
    private string $operatingStatus = self::OPERATING_CLOSED;

    #[Assert\Choice(choices: Restaurant::PAYOUT_METHODS)]
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $payoutMethod = null;

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

    public function getApprovalStatus(): string
    {
        return $this->approvalStatus;
    }

    public function setApprovalStatus(string $approvalStatus): self
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

    public function getOperatingStatus(): string
    {
        return $this->operatingStatus;
    }

    public function setOperatingStatus(string $operatingStatus): self
    {
        $this->operatingStatus = $operatingStatus;

        return $this;
    }

    public function getPayoutMethod(): ?string
    {
        return $this->payoutMethod;
    }

    public function setPayoutMethod(?string $payoutMethod): self
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

    /**
     * Whether $now falls inside the weekly schedule. A restaurant without a schedule follows
     * its manual open/closed toggle alone, so this is true for it. Overnight slots
     * (opening after closing time) run into the next day.
     */
    public function isWithinOperatingHours(\DateTimeImmutable $now): bool
    {
        if ($this->operatingHours->isEmpty()) {
            return true;
        }

        $today = (int) $now->format('w');
        $previousDay = (6 + $today) % 7;
        $time = $now->format('H:i:s');
        foreach ($this->operatingHours as $hours) {
            $opens = $hours->getOpensAt()->format('H:i:s');
            $closes = $hours->getClosesAt()->format('H:i:s');
            $overnight = $opens > $closes;
            if ($hours->getDayOfWeek() === $today && ((!$overnight && $time >= $opens && $time <= $closes) || ($overnight && $time >= $opens))) {
                return true;
            }
            if ($overnight && $hours->getDayOfWeek() === $previousDay && $time <= $closes) {
                return true;
            }
        }

        return false;
    }

    /** Open for orders right now: switched open and inside its schedule. */
    public function isOpenAt(\DateTimeImmutable $now): bool
    {
        return self::OPERATING_OPEN === $this->operatingStatus && $this->isWithinOperatingHours($now);
    }

    public function isOpenNow(): bool
    {
        return $this->isOpenAt(new \DateTimeImmutable());
    }

    /** Average review rating rounded to two places; 0 without reviews. */
    public function getAverageRating(): float
    {
        $count = $this->reviews->count();
        if (0 === $count) {
            return 0.0;
        }
        $sum = 0;
        foreach ($this->reviews as $review) {
            $sum += $review->getRating();
        }

        return round($sum / $count, 2);
    }
}
