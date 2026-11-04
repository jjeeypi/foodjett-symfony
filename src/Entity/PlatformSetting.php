<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlatformSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: PlatformSettingRepository::class)]
#[ORM\Table(name: 'platform_settings')]
#[ORM\UniqueConstraint(name: 'platform_settings_key_unique', columns: ['key'])]
#[UniqueEntity(fields: ['key'], message: 'That setting already exists; edit it instead.')]
class PlatformSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(name: '`key`', length: 255, unique: true)]
    #[Assert\NotBlank(message: 'Name the setting.')]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]{1,99}$/', message: 'Use lowercase letters, digits and underscores, starting with a letter.')]
    private string $key = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Enter a value.')]
    #[Assert\Length(max: 255)]
    private string $value = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?string { return $this->id; }
    public function getKey(): string { return $this->key; }
    public function setKey(string $key): self { $this->key = $key; return $this; }
    public function getValue(): string { return $this->value; }
    public function setValue(string $value): self { $this->value = $value; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    /** Amounts, rates, distances, limits and durations must be numbers of zero or more. */
    #[Assert\Callback]
    public function validateNumericValue(ExecutionContextInterface $context): void
    {
        if (1 === preg_match('/(?:radius|minutes|rate|amount|fee|pay|limit|threshold|compensation)/', $this->key)
            && (!is_numeric(trim($this->value)) || (float) $this->value < 0)) {
            $context->buildViolation('This setting must be a number of zero or more.')->atPath('value')->addViolation();
        }
    }
}
