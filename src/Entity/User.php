<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_email_unique', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'users_phone_unique', columns: ['phone'])]
#[UniqueEntity(fields: ['email'], message: 'This email address is already registered.')]
#[UniqueEntity(fields: ['phone'], message: 'This phone number is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    // Account role; getRoles() maps it to ROLE_* (ACCOUNT_ROLES)
    public const ACCOUNT_ADMIN = 'admin';
    public const ACCOUNT_RESTAURANT = 'restaurant';
    public const ACCOUNT_RIDER = 'rider';
    public const ACCOUNT_CUSTOMER = 'customer';

    /** @var list<string> */
    public const ACCOUNT_ROLES = [
        self::ACCOUNT_ADMIN,
        self::ACCOUNT_RESTAURANT,
        self::ACCOUNT_RIDER,
        self::ACCOUNT_CUSTOMER,
    ];

    // Account status (STATUSES)
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BANNED = 'banned';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_BANNED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\+?[0-9]{7,20}$/', message: 'Enter a valid phone number.')]
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $twoFactorSecret = null;

    #[ORM\Column(type: Types::TEXT, length: 65535, nullable: true)]
    private ?string $twoFactorRecoveryCodes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $twoFactorConfirmedAt = null;

    #[Assert\Choice(choices: User::ACCOUNT_ROLES)]
    #[ORM\Column(length: 32)]
    private string $role;

    #[Assert\Choice(choices: User::STATUSES)]
    #[ORM\Column(length: 32, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $phoneVerifiedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $rememberToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Admin::class)]
    private ?Admin $admin = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Restaurant::class)]
    private ?Restaurant $restaurant = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Rider::class)]
    private ?Rider $rider = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Customer::class)]
    private ?Customer $customer = null;

    /** @var Collection<int, OrderReport> */
    #[ORM\OneToMany(mappedBy: 'reportedBy', targetEntity: OrderReport::class, cascade: ['persist'])]
    private Collection $reportedOrderReports;

    /** @var Collection<int, SupportTicket> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: SupportTicket::class, cascade: ['persist'])]
    private Collection $supportTickets;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(mappedBy: 'sender', targetEntity: Message::class, cascade: ['persist'])]
    private Collection $sentMessages;

    /** @var Collection<int, Notification> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Notification::class, cascade: ['persist'])]
    private Collection $notifications;

    /** @var Collection<int, AuditLog> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: AuditLog::class, cascade: ['persist'])]
    private Collection $auditLogs;

    public function __construct()
    {
        $this->reportedOrderReports = new ArrayCollection();
        $this->supportTickets = new ArrayCollection();
        $this->sentMessages = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->auditLogs = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /** Emails are stored trimmed and lower-cased, so uniqueness and login ignore case. */
    public function setEmail(?string $email): self
    {
        $this->email = null === $email ? null : mb_strtolower(trim($email));

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = null === $phone ? null : trim($phone);

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->twoFactorSecret;
    }

    public function setTwoFactorSecret(?string $twoFactorSecret): self
    {
        $this->twoFactorSecret = $twoFactorSecret;

        return $this;
    }

    public function getTwoFactorRecoveryCodes(): ?string
    {
        return $this->twoFactorRecoveryCodes;
    }

    public function setTwoFactorRecoveryCodes(?string $twoFactorRecoveryCodes): self
    {
        $this->twoFactorRecoveryCodes = $twoFactorRecoveryCodes;

        return $this;
    }

    public function getTwoFactorConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->twoFactorConfirmedAt;
    }

    public function setTwoFactorConfirmedAt(?\DateTimeImmutable $twoFactorConfirmedAt): self
    {
        $this->twoFactorConfirmedAt = $twoFactorConfirmedAt;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER', 'ROLE_'.strtoupper($this->role)];
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

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function getPhoneVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->phoneVerifiedAt;
    }

    public function setPhoneVerifiedAt(?\DateTimeImmutable $phoneVerifiedAt): self
    {
        $this->phoneVerifiedAt = $phoneVerifiedAt;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): self
    {
        $this->avatarPath = $avatarPath;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken(?string $rememberToken): self
    {
        $this->rememberToken = $rememberToken;

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

    public function getAdmin(): ?Admin
    {
        return $this->admin;
    }

    public function setAdmin(?Admin $admin): self
    {
        $this->admin = $admin;

        return $this;
    }

    public function getRestaurant(): ?Restaurant
    {
        return $this->restaurant;
    }

    public function setRestaurant(?Restaurant $restaurant): self
    {
        $this->restaurant = $restaurant;

        return $this;
    }

    public function getRider(): ?Rider
    {
        return $this->rider;
    }

    public function setRider(?Rider $rider): self
    {
        $this->rider = $rider;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * The session keeps only these fields; relations are reloaded from the database on each request.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $keep = ['id', 'name', 'email', 'phone', 'password', 'role', 'status'];

        return array_filter(
            $data,
            static fn (string $key): bool => in_array(substr($key, strrpos($key, "\0") + 1), $keep, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Called with the user freshly loaded from the database. A changed password or role, or an
     * account that is no longer active, ends the session on the next request. A changed email
     * does not: users may edit it on their profile while signed in.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->getId() === $this->id
            && $user->getPassword() === $this->password
            && $user->getRole() === $this->role
            && self::STATUS_ACTIVE === $user->getStatus();
    }

    /** @return Collection<int, OrderReport> */
    public function getReportedOrderReports(): Collection { return $this->reportedOrderReports; }

    /** @return Collection<int, SupportTicket> */
    public function getSupportTickets(): Collection { return $this->supportTickets; }

    /** @return Collection<int, Message> */
    public function getSentMessages(): Collection { return $this->sentMessages; }

    /** @return Collection<int, Notification> */
    public function getNotifications(): Collection { return $this->notifications; }

    /** @return Collection<int, AuditLog> */
    public function getAuditLogs(): Collection { return $this->auditLogs; }
}
