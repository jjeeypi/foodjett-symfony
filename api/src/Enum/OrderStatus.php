<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderStatus: string
{
    case PLACED = 'placed';
    case ACCEPTED = 'accepted';
    case PREPARING = 'preparing';
    case READY = 'ready';
    case FINDING_RIDER = 'finding_rider';
    case RIDER_ASSIGNED = 'rider_assigned';
    case AT_RESTAURANT = 'at_restaurant';
    case PICKED_UP = 'picked_up';
    case ON_THE_WAY = 'on_the_way';
    case ARRIVED = 'arrived';
    case DELIVERED = 'delivered';
    case REJECTED_BY_RESTAURANT = 'rejected_by_restaurant';
    case CANCELLED_BY_CUSTOMER = 'cancelled_by_customer';
    case CANCELLED_BY_RESTAURANT = 'cancelled_by_restaurant';
    case CANCELLED_NO_RIDER = 'cancelled_no_rider';
    case CANCELLED_BY_ADMIN = 'cancelled_by_admin';
    case FAILED_DELIVERY = 'failed_delivery';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PLACED => [
                self::ACCEPTED,
                self::REJECTED_BY_RESTAURANT,
                self::CANCELLED_BY_CUSTOMER,
                self::CANCELLED_BY_ADMIN,
            ],
            self::ACCEPTED => [
                self::PREPARING,
                self::CANCELLED_BY_RESTAURANT,
                self::CANCELLED_BY_ADMIN,
            ],
            self::PREPARING => [
                self::READY,
                self::FINDING_RIDER,
                self::CANCELLED_BY_RESTAURANT,
                self::CANCELLED_BY_ADMIN,
            ],
            self::READY => [
                self::FINDING_RIDER,
                self::CANCELLED_BY_RESTAURANT,
                self::CANCELLED_BY_ADMIN,
            ],
            self::FINDING_RIDER => [
                self::RIDER_ASSIGNED,
                self::CANCELLED_BY_CUSTOMER,
                self::CANCELLED_BY_RESTAURANT,
                self::CANCELLED_NO_RIDER,
                self::CANCELLED_BY_ADMIN,
            ],
            self::RIDER_ASSIGNED => [
                self::AT_RESTAURANT,
                self::FINDING_RIDER,
                self::CANCELLED_BY_ADMIN,
                self::FAILED_DELIVERY,
            ],
            self::AT_RESTAURANT => [
                self::PICKED_UP,
                self::FINDING_RIDER,
                self::CANCELLED_BY_ADMIN,
                self::FAILED_DELIVERY,
            ],
            self::PICKED_UP => [
                self::ON_THE_WAY,
                self::CANCELLED_BY_ADMIN,
                self::FAILED_DELIVERY,
            ],
            self::ON_THE_WAY => [
                self::ARRIVED,
                self::CANCELLED_BY_ADMIN,
                self::FAILED_DELIVERY,
            ],
            self::ARRIVED => [
                self::DELIVERED,
                self::CANCELLED_BY_ADMIN,
                self::FAILED_DELIVERY,
            ],
            self::DELIVERED,
            self::REJECTED_BY_RESTAURANT,
            self::CANCELLED_BY_CUSTOMER,
            self::CANCELLED_BY_RESTAURANT,
            self::CANCELLED_NO_RIDER,
            self::CANCELLED_BY_ADMIN,
            self::FAILED_DELIVERY => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return [] === $this->allowedTransitions();
    }
}
