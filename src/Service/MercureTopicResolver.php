<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\UserRole;
use App\Repository\ConversationRepository;
use App\Repository\OrderRepository;
use App\Repository\RestaurantRepository;

final readonly class MercureTopicResolver
{
    public function __construct(
        private OrderRepository $orders,
        private ConversationRepository $conversations,
        private RestaurantRepository $restaurants,
    ) {
    }

    /** @return list<string> */
    public function subscriberTopics(User $user): array
    {
        $topics = match ($user->getRole()) {
            UserRole::ADMIN => $this->adminTopics(),
            UserRole::RESTAURANT => $this->restaurantTopics($user),
            UserRole::RIDER => $this->riderTopics($user),
            UserRole::CUSTOMER => $this->customerTopics($user),
        };

        $topics = array_values(array_unique($topics));
        sort($topics);

        return $topics;
    }

    /** @return list<string> */
    private function restaurantTopics(User $user): array
    {
        $restaurant = $user->getRestaurant();

        return null === $restaurant || null === $restaurant->getId()
            ? []
            : [sprintf('restaurant/%s/orders', $restaurant->getId())];
    }

    /** @return list<string> */
    private function customerTopics(User $user): array
    {
        $customer = $user->getCustomer();
        if (null === $customer) {
            return [];
        }

        $orderIds = $this->orders->findIdsForCustomer($customer);
        $conversationIds = $this->conversations->findIdsForCustomer($customer);

        return [
            ...array_map(static fn (mixed $id): string => sprintf('orders/%s/status', $id), $orderIds),
            ...array_map(static fn (mixed $id): string => sprintf('conversations/%s', $id), $conversationIds),
            sprintf('users/%s/notifications', $user->getId()),
        ];
    }

    /** @return list<string> */
    private function riderTopics(User $user): array
    {
        $rider = $user->getRider();
        if (null === $rider) {
            return [];
        }

        $orderIds = $this->orders->findActiveIdsForRider($rider);
        $conversationIds = $this->conversations->findCustomerRiderIdsForRider($rider);

        $topics = [
            ...array_map(static fn (mixed $id): string => sprintf('orders/%s/status', $id), $orderIds),
            ...array_map(static fn (mixed $id): string => sprintf('conversations/%s', $id), $conversationIds),
            sprintf('users/%s/notifications', $user->getId()),
        ];

        if (ApprovalStatus::APPROVED === $rider->getApprovalStatus()) {
            $topics[] = 'orders/pool';
        }

        return $topics;
    }

    /** @return list<string> */
    private function adminTopics(): array
    {
        $restaurantIds = $this->restaurants->findAllIds();
        $orderIds = $this->orders->findAllIds();
        $conversationIds = $this->conversations->findAllIds();

        return [
            'orders/pool',
            ...array_map(static fn (mixed $id): string => sprintf('restaurant/%s/orders', $id), $restaurantIds),
            ...array_map(static fn (mixed $id): string => sprintf('orders/%s/status', $id), $orderIds),
            ...array_map(static fn (mixed $id): string => sprintf('conversations/%s', $id), $conversationIds),
        ];
    }
}
