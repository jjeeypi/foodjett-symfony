<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Enum\ApprovalStatus;
use App\Enum\ConversationType;
use App\Enum\OrderStatus;
use App\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MercureTopicResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
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

        $orderIds = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->select('orders.id')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getSingleColumnResult();
        $conversationIds = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->select('conversation.id')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.customer = :customer')->setParameter('customer', $customer)
            ->getQuery()->getSingleColumnResult();

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

        $terminalStatuses = array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isTerminal()),
        );
        $orderIds = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->select('orders.id')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('orders.status NOT IN (:terminalStatuses)')->setParameter('terminalStatuses', $terminalStatuses)
            ->getQuery()->getSingleColumnResult();
        $conversationIds = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->select('conversation.id')
            ->innerJoin('conversation.order', 'orders')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $rider)
            ->andWhere('conversation.type = :type')->setParameter('type', ConversationType::CUSTOMER_RIDER)
            ->getQuery()->getSingleColumnResult();

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
        $restaurantIds = $this->entityManager->getRepository(Restaurant::class)->createQueryBuilder('restaurant')
            ->select('restaurant.id')->getQuery()->getSingleColumnResult();
        $orderIds = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->select('orders.id')->getQuery()->getSingleColumnResult();
        $conversationIds = $this->entityManager->getRepository(Conversation::class)->createQueryBuilder('conversation')
            ->select('conversation.id')->getQuery()->getSingleColumnResult();

        return [
            'orders/pool',
            ...array_map(static fn (mixed $id): string => sprintf('restaurant/%s/orders', $id), $restaurantIds),
            ...array_map(static fn (mixed $id): string => sprintf('orders/%s/status', $id), $orderIds),
            ...array_map(static fn (mixed $id): string => sprintf('conversations/%s', $id), $conversationIds),
        ];
    }
}
