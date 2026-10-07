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
}
