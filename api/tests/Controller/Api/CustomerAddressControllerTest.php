<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CustomerAddress;

final class CustomerAddressControllerTest extends CustomerApiTestCase
{
    public function testAddressCrudAndDefaultPromotion(): void
    {
        $this->client->jsonRequest('POST', '/api/customer/addresses', [
            'label' => 'Work', 'address_line' => '456 Office Avenue', 'landmark' => 'City Hall',
            'instructions' => 'Lobby reception', 'latitude' => '14.6100000', 'longitude' => '120.9900000', 'is_default' => true,
        ], server: $this->auth());
        self::assertResponseStatusCodeSame(201);
        $id = (string) $this->payload()['address']['id'];
        /** @var CustomerAddress $work */
        $work = $this->fresh(CustomerAddress::class, $id);
        /** @var CustomerAddress $home */
        $home = $this->fresh(CustomerAddress::class, (string) $this->address->getId());
        self::assertTrue($work->isDefault());
        self::assertFalse($home->isDefault());

        $this->client->jsonRequest('PATCH', '/api/customer/addresses/'.$id, [
            'label' => 'Office', 'delivery_instructions' => 'Call reception',
        ], server: $this->auth());
        self::assertResponseIsSuccessful();
        /** @var CustomerAddress $work */
        $work = $this->fresh(CustomerAddress::class, $id);
        self::assertSame('Office', $work->getLabel());
        self::assertSame('Call reception', $work->getDeliveryInstructions());

        $this->client->request('DELETE', '/api/customer/addresses/'.$id, server: $this->auth());
        self::assertResponseStatusCodeSame(204);
        /** @var CustomerAddress $home */
        $home = $this->fresh(CustomerAddress::class, (string) $this->address->getId());
        self::assertTrue($home->isDefault());
    }

    public function testAddressListAndMutationsAreCustomerScoped(): void
    {
        $this->client->request('GET', '/api/customer/addresses', server: $this->auth());
        self::assertResponseIsSuccessful();
        $ids = array_column($this->payload()['addresses'], 'id');
        self::assertContains((string) $this->address->getId(), $ids);
        self::assertNotContains((string) $this->otherAddress->getId(), $ids);

        $this->client->jsonRequest('PATCH', '/api/customer/addresses/'.$this->otherAddress->getId(), ['label' => 'Stolen'], server: $this->auth());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/customer/addresses/'.$this->otherAddress->getId(), server: $this->auth());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAddressReferencedByOrderCannotBeDeleted(): void
    {
        $order = $this->createOrder($this->customer, $this->restaurant, $this->address);
        $this->entityManager->flush();

        $this->client->request('DELETE', '/api/customer/addresses/'.$this->address->getId(), server: $this->auth());

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('order history', $this->payload()['message']);
        /** @var CustomerAddress $address */
        $address = $this->fresh(CustomerAddress::class, (string) $this->address->getId());
        self::assertSame($this->address->getId(), $address->getId());
        self::assertSame($address->getId(), $order->getCustomerAddress()->getId());
    }
}
