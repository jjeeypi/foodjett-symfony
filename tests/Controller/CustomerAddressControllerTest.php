<?php

namespace App\Tests\Controller;

use App\Entity\CustomerAddress;

/** Address book: defaults, ownership and addresses kept for order history. */
class CustomerAddressControllerTest extends CustomerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->customerUser, 'main');
    }

    public function testNewDefaultReplacesTheOldOneAndDeletingItPromotesAnother(): void
    {
        $this->add('Office', '45 Work Avenue', default: true);
        self::assertResponseRedirects('/account/addresses');

        $addresses = $this->addresses();
        self::assertCount(2, $addresses);
        $office = $this->byLabel($addresses, 'Office');
        self::assertTrue($office->isDefault());
        self::assertFalse($this->byLabel($addresses, $this->address->getLabel())->isDefault());

        $crawler = $this->client->request('GET', '/account/addresses/'.$office->getId().'/edit');
        $this->client->submit($crawler->selectButton('Update')->form(['customer_address[landmark]' => 'Blue gate']));
        $office = $this->byLabel($this->addresses(), 'Office');
        self::assertSame('Blue gate', $office->getLandmark());
        self::assertTrue($office->isDefault(), 'Editing the default address keeps it the default.');

        $this->delete($office);
        $addresses = $this->addresses();
        self::assertCount(1, $addresses);
        self::assertTrue($addresses[0]->isDefault(), 'The remaining address becomes the default.');
    }

    public function testInvalidCoordinatesAreRejected(): void
    {
        $this->add('Bad', 'Nowhere', latitude: '123');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Latitude must be between -90 and 90.');
        self::assertCount(1, $this->addresses());
    }

    public function testAnotherCustomersAddressIsNotFound(): void
    {
        $this->client->request('GET', '/account/addresses/'.$this->otherAddress->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/account/addresses');
        self::assertStringNotContainsString($this->otherAddress->getLabel(), $crawler->filter('main')->text());
    }

    public function testAnAddressUsedByAnOrderCannotBeDeleted(): void
    {
        $this->createOrder($this->customer, $this->restaurant, $this->address);
        $this->entityManager->flush();

        $this->delete($this->address);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'part of your order history');
        self::assertCount(1, $this->addresses());
    }

    private function add(string $label, string $line, bool $default = false, string $latitude = '14.6100000'): void
    {
        $crawler = $this->client->request('GET', '/account/addresses/new');
        $form = $crawler->selectButton('Save')->form([
            'customer_address[label]' => $label,
            'customer_address[addressLine]' => $line,
            'customer_address[latitude]' => $latitude,
            'customer_address[longitude]' => '120.9900000',
        ]);
        if ($default) {
            $form['customer_address[isDefault]']->tick();
        }
        $this->client->submit($form);
    }

    private function delete(CustomerAddress $address): void
    {
        $this->entityManager->clear();
        $crawler = $this->client->request('GET', '/account/addresses');
        $this->client->submit($crawler->filter('form[action$="/account/addresses/'.$address->getId().'"]')->form());
    }

    /** @return list<CustomerAddress> */
    private function addresses(): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(CustomerAddress::class)->findBy(['customer' => $this->customer->getId()]);
    }

    /** @param list<CustomerAddress> $addresses */
    private function byLabel(array $addresses, string $label): CustomerAddress
    {
        foreach ($addresses as $address) {
            if ($address->getLabel() === $label) {
                return $address;
            }
        }
        self::fail('No address labelled '.$label);
    }
}
