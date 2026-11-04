<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rider;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\OrderRepository;
use App\Repository\RestaurantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MercureAuthorizationController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ConversationRepository $conversations,
        private readonly RestaurantRepository $restaurants,
    ) {
    }

    #[Route('/api/mercure-auth', name: 'api_mercure_auth', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request, Authorization $authorization, HubInterface $hub): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $topics = $this->subscriberTopics($user);
        $authorization->setCookie($request, $topics, additionalClaims: [
            'sub' => 'user/'.$user->getId(),
            'client_id' => 'foodjett-spa',
        ]);

        return $this->json([
            'hub_url' => $hub->getPublicUrl(),
            'subscription_parameter' => 'match',
            'topics' => $topics,
        ]);
    }

    /**
     * The private Mercure topics this user may subscribe to.
     *
     * @return list<string>
     */
    private function subscriberTopics(User $user): array
    {
        $topics = match ($user->getRole()) {
            User::ACCOUNT_ADMIN => $this->adminTopics(),
            User::ACCOUNT_RESTAURANT => $this->restaurantTopics($user),
            User::ACCOUNT_RIDER => $this->riderTopics($user),
            User::ACCOUNT_CUSTOMER => $this->customerTopics($user),
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

        if (Rider::APPROVAL_APPROVED === $rider->getApprovalStatus()) {
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
