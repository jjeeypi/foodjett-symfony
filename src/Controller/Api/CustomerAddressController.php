<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\CustomerAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/customer/addresses', name: 'api_customer_addresses_')]
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerAddressController extends AbstractCustomerController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $addresses = $this->entityManager->getRepository(CustomerAddress::class)->createQueryBuilder('address')
            ->andWhere('address.customer = :customer')->setParameter('customer', $this->customer())
            ->orderBy('address.isDefault', 'DESC')->addOrderBy('address.createdAt', 'DESC')->getQuery()->getResult();

        return $this->json(['addresses' => array_map($this->serialize(...), $addresses)]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validate($data);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $customer = $this->customer();
        $makeDefault = $this->boolean($data['is_default'] ?? false);
        if (0 === $this->entityManager->getRepository(CustomerAddress::class)->count(['customer' => $customer])) {
            $makeDefault = true;
        }
        $now = new \DateTimeImmutable();
        $address = (new CustomerAddress())->setCustomer($customer)->setCreatedAt($now);
        $this->apply($address, $data);
        $address->setIsDefault($makeDefault)->setUpdatedAt($now);
        $this->entityManager->wrapInTransaction(function () use ($customer, $address, $makeDefault): void {
            if ($makeDefault) {
                $this->unsetDefaults($customer->getId());
            }
            $this->entityManager->persist($address);
            $this->entityManager->flush();
        });

        return $this->json(['message' => 'Address created.', 'address' => $this->serialize($address)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $address = $this->ownedAddress($id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->validate($data, true);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $makeDefault = array_key_exists('is_default', $data) && $this->boolean($data['is_default']);
        $this->entityManager->wrapInTransaction(function () use ($address, $data, $makeDefault): void {
            if ($makeDefault) {
                $this->unsetDefaults($address->getCustomer()->getId());
                $address->setIsDefault(true);
            } elseif (array_key_exists('is_default', $data) && !$this->boolean($data['is_default'])) {
                $address->setIsDefault(false);
            }
            $this->apply($address, $data);
            $address->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        });

        return $this->json(['message' => 'Address updated.', 'address' => $this->serialize($address)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(string $id): JsonResponse
    {
        $address = $this->ownedAddress($id);
        if (!$address->getOrders()->isEmpty()) {
            return $this->json(['message' => 'This address is part of order history and cannot be deleted.'], 409);
        }
        $wasDefault = $address->isDefault();
        $customer = $address->getCustomer();
        $this->entityManager->wrapInTransaction(function () use ($address, $wasDefault, $customer): void {
            $this->entityManager->remove($address);
            $this->entityManager->flush();
            if ($wasDefault) {
                $replacement = $this->entityManager->getRepository(CustomerAddress::class)->createQueryBuilder('candidate')
                    ->andWhere('candidate.customer = :customer')->setParameter('customer', $customer)
                    ->orderBy('candidate.createdAt', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
                if ($replacement instanceof CustomerAddress) {
                    $replacement->setIsDefault(true)->setUpdatedAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                }
            }
        });

        return $this->json(null, 204);
    }

    private function ownedAddress(string $id): CustomerAddress
    {
        $address = $this->entityManager->getRepository(CustomerAddress::class)->createQueryBuilder('address')
            ->andWhere('address.id = :id')->setParameter('id', $id)
            ->andWhere('address.customer = :customer')->setParameter('customer', $this->customer())
            ->getQuery()->getOneOrNullResult();
        if (!$address instanceof CustomerAddress) {
            throw $this->createNotFoundException('Address not found.');
        }

        return $address;
    }

    private function unsetDefaults(?string $customerId): void
    {
        $this->entityManager->createQueryBuilder()->update(CustomerAddress::class, 'address')->set('address.isDefault', ':false')
            ->andWhere('IDENTITY(address.customer) = :customer')->setParameter('false', false)->setParameter('customer', $customerId)
            ->getQuery()->execute();
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, bool $partial = false): ?JsonResponse
    {
        $errors = [];
        foreach (['label', 'address_line'] as $required) {
            if ((!$partial || array_key_exists($required, $data)) && '' === trim((string) ($data[$required] ?? ''))) {
                $errors[$required][] = 'This value is required.';
            }
        }
        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $field => [$minimum, $maximum]) {
            if ((!$partial || array_key_exists($field, $data)) && (!is_numeric($data[$field] ?? null) || (float) $data[$field] < $minimum || (float) $data[$field] > $maximum)) {
                $errors[$field][] = sprintf('%s must be between %d and %d.', ucfirst($field), $minimum, $maximum);
            }
        }
        if (array_key_exists('is_default', $data) && null === filter_var($data['is_default'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)) {
            $errors['is_default'][] = 'is_default must be boolean.';
        }

        return [] === $errors ? null : $this->json(['message' => 'The submitted data is invalid.', 'errors' => $errors], 422);
    }

    /** @param array<string, mixed> $data */
    private function apply(CustomerAddress $address, array $data): void
    {
        if (array_key_exists('label', $data)) {
            $address->setLabel(trim((string) $data['label']));
        }
        if (array_key_exists('address_line', $data)) {
            $address->setAddressLine(trim((string) $data['address_line']));
        }
        if (array_key_exists('landmark', $data)) {
            $address->setLandmark($this->nullable($data['landmark']));
        }
        if (array_key_exists('delivery_instructions', $data) || array_key_exists('instructions', $data)) {
            $address->setDeliveryInstructions($this->nullable($data['delivery_instructions'] ?? $data['instructions']));
        }
        if (array_key_exists('latitude', $data)) {
            $address->setLatitude((string) $data['latitude']);
        }
        if (array_key_exists('longitude', $data)) {
            $address->setLongitude((string) $data['longitude']);
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function boolean(mixed $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array<string, mixed> */
    private function serialize(CustomerAddress $address): array
    {
        return [
            'id' => $address->getId(), 'label' => $address->getLabel(), 'address_line' => $address->getAddressLine(),
            'landmark' => $address->getLandmark(), 'delivery_instructions' => $address->getDeliveryInstructions(),
            'latitude' => $address->getLatitude(), 'longitude' => $address->getLongitude(), 'is_default' => $address->isDefault(),
        ];
    }
}
