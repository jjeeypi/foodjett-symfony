<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\UniqueConstraint(name: 'conversations_order_id_type_unique', columns: ['order_id', 'type'])]
class Conversation
{
    // Who the conversation is between (TYPES)
    public const TYPE_CUSTOMER_RIDER = 'customer_rider';
    public const TYPE_CUSTOMER_RESTAURANT = 'customer_restaurant';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_CUSTOMER_RIDER,
        self::TYPE_CUSTOMER_RESTAURANT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'conversations', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[Assert\Choice(choices: Conversation::TYPES)]
    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP NULL DEFAULT NULL')]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?string { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): self { $this->order = $order; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $at): self { $this->closedAt = $at; return $this; }
    public function isClosed(?\DateTimeImmutable $at = null): bool { return null !== $this->closedAt && $this->closedAt <= ($at ?? new \DateTimeImmutable()); }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $at): self { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $at): self { $this->updatedAt = $at; return $this; }
    /** @return Collection<int, Message> */
    public function getMessages(): Collection { return $this->messages; }
    public function addMessage(Message $message): self { if (!$this->messages->contains($message)) { $this->messages->add($message); $message->setConversation($this); } return $this; }
}
