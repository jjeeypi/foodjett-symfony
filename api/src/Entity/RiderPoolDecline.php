<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RiderPoolDeclineAction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'rider_pool_declines',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'rider_pool_declines_order_rider_unique', columns: ['order_id', 'rider_id']),
    ],
)]
class RiderPoolDecline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'riderPoolDeclines', targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\ManyToOne(inversedBy: 'poolDeclines', targetEntity: Rider::class)]
    #[ORM\JoinColumn(name: 'rider_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Rider $rider;

    #[ORM\Column(length: 16, enumType: RiderPoolDeclineAction::class)]
    private RiderPoolDeclineAction $action;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, columnDefinition: 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getRider(): Rider
    {
        return $this->rider;
    }

    public function setRider(Rider $rider): self
    {
        $this->rider = $rider;

        return $this;
    }

    public function getAction(): RiderPoolDeclineAction
    {
        return $this->action;
    }

    public function setAction(RiderPoolDeclineAction $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
