<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use App\Enum\ApprovalStatus;
use App\Enum\ConversationType;
use App\Enum\OrderStatus;
use App\Enum\UserRole;
use App\Security\Voter\ConversationVoter;
use App\Security\Voter\OrderVoter;
use App\Security\Voter\RestaurantVoter;
use App\Security\Voter\RiderVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class OwnershipVotersTest extends TestCase
{
    public function testOrderOwnershipAndPoolRules(): void
    {
        [$order, $customerUser, $restaurantUser, $riderUser] = $this->orderGraph();
        $voter = new OrderVoter();

        self::assertTrue($this->granted($voter, OrderVoter::VIEW, $order, $customerUser));
        self::assertTrue($this->granted($voter, OrderVoter::CANCEL, $order, $customerUser));
        self::assertTrue($this->granted($voter, OrderVoter::UPDATE_AS_RESTAURANT, $order, $restaurantUser));
        self::assertTrue($this->granted($voter, OrderVoter::UPDATE_AS_RIDER, $order, $riderUser));

        $unapprovedRider = $this->riderUser(ApprovalStatus::PENDING);
        self::assertFalse($this->granted($voter, OrderVoter::UPDATE_AS_RIDER, $order, $unapprovedRider));

        $order->setStatus(OrderStatus::PLACED);
        self::assertFalse($this->granted($voter, OrderVoter::UPDATE_AS_RIDER, $order, $riderUser));

        $order->setRider($riderUser->getRider());
        self::assertTrue($this->granted($voter, OrderVoter::VIEW, $order, $riderUser));
        self::assertFalse($this->granted($voter, OrderVoter::VIEW, $order, $unapprovedRider));

        $admin = $this->user(UserRole::ADMIN);
        self::assertTrue($this->granted($voter, OrderVoter::CANCEL, $order, $admin));
    }

    public function testRestaurantAndRiderVotersAllowSelfAndAdminOnly(): void
    {
        [, , $restaurantUser, $riderUser] = $this->orderGraph();
        $admin = $this->user(UserRole::ADMIN);
        $outsider = $this->user(UserRole::CUSTOMER);

        $restaurantVoter = new RestaurantVoter();
        self::assertTrue($this->granted($restaurantVoter, RestaurantVoter::UPDATE, $restaurantUser->getRestaurant(), $restaurantUser));
        self::assertFalse($this->granted($restaurantVoter, RestaurantVoter::UPDATE, $restaurantUser->getRestaurant(), $outsider));
        self::assertTrue($this->granted($restaurantVoter, RestaurantVoter::APPROVE, $restaurantUser->getRestaurant(), $admin));

        $riderVoter = new RiderVoter();
        self::assertTrue($this->granted($riderVoter, RiderVoter::UPDATE, $riderUser->getRider(), $riderUser));
        self::assertFalse($this->granted($riderVoter, RiderVoter::SUSPEND, $riderUser->getRider(), $riderUser));
        self::assertTrue($this->granted($riderVoter, RiderVoter::SUSPEND, $riderUser->getRider(), $admin));
    }

    public function testConversationParticipantsAndAdminReadOnlyException(): void
    {
        [$order, $customerUser, $restaurantUser, $riderUser] = $this->orderGraph();
        $order->setRider($riderUser->getRider());
        $conversation = (new Conversation())
            ->setOrder($order)
            ->setType(ConversationType::CUSTOMER_RIDER);
        $voter = new ConversationVoter();

        self::assertTrue($this->granted($voter, ConversationVoter::SEND, $conversation, $customerUser));
        self::assertTrue($this->granted($voter, ConversationVoter::SEND, $conversation, $riderUser));
        self::assertFalse($this->granted($voter, ConversationVoter::VIEW, $conversation, $restaurantUser));

        $admin = $this->user(UserRole::ADMIN);
        self::assertTrue($this->granted($voter, ConversationVoter::VIEW, $conversation, $admin));
        self::assertFalse($this->granted($voter, ConversationVoter::SEND, $conversation, $admin));

        $conversation->setClosedAt(new \DateTimeImmutable());
        self::assertTrue($this->granted($voter, ConversationVoter::VIEW, $conversation, $customerUser));
        self::assertFalse($this->granted($voter, ConversationVoter::SEND, $conversation, $customerUser));
    }

    /** @return array{Order, User, User, User} */
    private function orderGraph(): array
    {
        $customerUser = $this->user(UserRole::CUSTOMER);
        $customer = (new Customer())->setUser($customerUser);
        $restaurantUser = $this->user(UserRole::RESTAURANT);
        $restaurant = (new Restaurant())->setUser($restaurantUser);
        $riderUser = $this->riderUser(ApprovalStatus::APPROVED);

        $order = (new Order())
            ->setCustomer($customer)
            ->setRestaurant($restaurant)
            ->setStatus(OrderStatus::FINDING_RIDER);

        return [$order, $customerUser, $restaurantUser, $riderUser];
    }

    private function riderUser(ApprovalStatus $approvalStatus): User
    {
        $user = $this->user(UserRole::RIDER);
        (new Rider())->setUser($user)->setApprovalStatus($approvalStatus);

        return $user;
    }

    private function user(UserRole $role): User
    {
        return (new User())->setRole($role);
    }

    private function granted(VoterInterface $voter, string $attribute, object $subject, User $user): bool
    {
        $token = new UsernamePasswordToken($user, 'test', $user->getRoles());

        return VoterInterface::ACCESS_GRANTED === $voter->vote($token, $subject, [$attribute]);
    }
}
