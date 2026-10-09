<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Voucher;
use App\Enum\VoucherScope;
use App\Enum\VoucherType;

final class RestaurantVoucherControllerTest extends RestaurantApiTestCase
{
    public function testRestaurantVoucherCrudHappyPath(): void
    {
        $code = 'SAVE'.strtoupper(bin2hex(random_bytes(3)));
        $this->client->jsonRequest('POST', '/api/restaurant/vouchers', [
            'code' => $code, 'type' => 'percentage', 'value' => '10.00', 'min_order_amount' => '200.00',
            'usage_limit_total' => 100, 'usage_limit_per_customer' => 2, 'is_active' => true,
        ], server: $this->auth());

        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->payload()['voucher']['id'];
        /** @var Voucher $voucher */
        $voucher = $this->fresh(Voucher::class, $id);
        self::assertSame(VoucherScope::RESTAURANT, $voucher->getScope());
        self::assertSame($this->restaurant->getId(), $voucher->getRestaurant()?->getId());
        self::assertSame(VoucherType::PERCENTAGE, $voucher->getType());

        $this->client->request('GET', '/api/restaurant/vouchers', server: $this->auth());
        self::assertResponseIsSuccessful();
        self::assertContains($id, array_column($this->payload()['data'], 'id'));

        $this->client->jsonRequest('PATCH', '/api/restaurant/vouchers/'.$id, [
            'type' => 'fixed', 'value' => '75.00', 'is_active' => false,
        ], server: $this->auth());
        self::assertResponseIsSuccessful();
        /** @var Voucher $voucher */
        $voucher = $this->fresh(Voucher::class, $id);
        self::assertSame(VoucherType::FIXED, $voucher->getType());
        self::assertSame('75.00', $voucher->getValue());
        self::assertFalse($voucher->isActive());

        $this->client->request('DELETE', '/api/restaurant/vouchers/'.$id, server: $this->auth());
        self::assertResponseStatusCodeSame(204);
        $this->entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Voucher::class, $id));
    }

    public function testRestaurantCannotCreatePlatformScopeOrEditForeignAndPlatformVouchers(): void
    {
        $now = new \DateTimeImmutable();
        $platform = $this->voucher('PLATFORM'.strtoupper(bin2hex(random_bytes(3))), VoucherScope::PLATFORM, null, $now);
        $foreign = $this->voucher('FOREIGN'.strtoupper(bin2hex(random_bytes(3))), VoucherScope::RESTAURANT, $this->otherRestaurant, $now);
        $this->persist($platform, $foreign);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/restaurant/vouchers', [
            'code' => 'SCOPED'.strtoupper(bin2hex(random_bytes(3))), 'scope' => 'platform',
            'type' => 'fixed', 'value' => '50.00',
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        $createdId = (string) $this->payload()['voucher']['id'];
        /** @var Voucher $created */
        $created = $this->fresh(Voucher::class, $createdId);
        self::assertSame(VoucherScope::RESTAURANT, $created->getScope());
        self::assertSame($this->restaurant->getId(), $created->getRestaurant()?->getId());

        $this->client->jsonRequest('PATCH', '/api/restaurant/vouchers/'.$platform->getId(), ['value' => '10.00'], server: $this->auth());
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('PATCH', '/api/restaurant/vouchers/'.$foreign->getId(), ['value' => '10.00'], server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    private function voucher(string $code, VoucherScope $scope, ?\App\Entity\Restaurant $restaurant, \DateTimeImmutable $now): Voucher
    {
        return (new Voucher())->setCode($code)->setScope($scope)->setRestaurant($restaurant)->setType(VoucherType::FIXED)
            ->setValue('25.00')->setMinOrderAmount('0.00')->setUsageLimitPerCustomer(1)->setIsActive(true)
            ->setCreatedAt($now)->setUpdatedAt($now);
    }
}
