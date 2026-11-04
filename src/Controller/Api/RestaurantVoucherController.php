<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Voucher;
use App\Repository\VoucherRepository;
use App\Security\Voter\ApprovedAccountVoter;
use App\Service\ApiPaginator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/restaurant/vouchers', name: 'api_restaurant_vouchers_')]
#[IsGranted('ROLE_RESTAURANT')]
#[IsGranted(ApprovedAccountVoter::ACCESS, message: 'Your restaurant account is awaiting approval.')]
final class RestaurantVoucherController extends AbstractRestaurantController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly VoucherRepository $vouchers,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->vouchers->createForRestaurantQuery($this->restaurant());

        return $this->json($this->paginator->paginate($query, $request, $this->serialize(...)));
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $voucher = new Voucher();
        $error = $this->apply($voucher, $data, true);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $now = new \DateTimeImmutable();
        $voucher->setScope(Voucher::SCOPE_RESTAURANT)->setRestaurant($this->restaurant())->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($voucher);
        $this->entityManager->flush();

        return $this->json(['message' => 'Voucher created.', 'voucher' => $this->serialize($voucher)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $voucher = $this->ownedVoucher($id);
        $data = $this->body($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $error = $this->apply($voucher, $data, false);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        $voucher->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->json(['message' => 'Voucher updated.', 'voucher' => $this->serialize($voucher)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(string $id): JsonResponse
    {
        $voucher = $this->ownedVoucher($id);
        if (!$voucher->getRedemptions()->isEmpty()) {
            return $this->json(['message' => 'A redeemed voucher cannot be deleted; deactivate it instead.'], 409);
        }
        $this->entityManager->remove($voucher);
        $this->entityManager->flush();

        return $this->json(null, 204);
    }

    private function ownedVoucher(string $id): Voucher
    {
        $voucher = $this->vouchers->findOneForRestaurant($id, $this->restaurant());
        if (!$voucher instanceof Voucher) {
            throw $this->createNotFoundException('Voucher not found.');
        }

        return $voucher;
    }

    /** @param array<string, mixed> $data */
    private function apply(Voucher $voucher, array $data, bool $creating): ?JsonResponse
    {
        $code = array_key_exists('code', $data) ? mb_strtoupper(trim((string) $data['code'])) : ($creating ? '' : $voucher->getCode());
        if ('' === $code || mb_strlen($code) > 255) {
            return $this->json(['message' => 'code is required and cannot exceed 255 characters.'], 422);
        }
        $duplicate = $this->entityManager->getRepository(Voucher::class)->findOneBy(['code' => $code]);
        if ($duplicate instanceof Voucher && $duplicate->getId() !== $voucher->getId()) {
            return $this->json(['message' => 'A voucher with that code already exists.'], 409);
        }
        $type = array_key_exists('type', $data) ? (in_array((string) $data['type'], Voucher::TYPES, true) ? (string) $data['type'] : null) : ($creating ? null : $voucher->getType());
        if (null === $type) {
            return $this->json(['message' => 'Invalid or missing type.'], 422);
        }
        $rawValue = array_key_exists('value', $data) ? $data['value'] : ($creating ? null : $voucher->getValue());
        $value = null === $rawValue || '' === $rawValue ? null : $this->decimalInput($rawValue);
        if (Voucher::TYPE_FREE_DELIVERY !== $type && (null === $value || 1 !== bccomp($value, '0', 2))) {
            return $this->json(['message' => 'value must be greater than zero for percentage and fixed vouchers.'], 422);
        }
        if (Voucher::TYPE_PERCENTAGE === $type && 1 === bccomp((string) $value, '100', 2)) {
            return $this->json(['message' => 'Percentage value cannot exceed 100.'], 422);
        }
        $minimum = $this->decimalInput(array_key_exists('min_order_amount', $data) ? $data['min_order_amount'] : ($creating ? 0 : $voucher->getMinOrderAmount()));
        if (null === $minimum) {
            return $this->json(['message' => 'min_order_amount must be non-negative.'], 422);
        }
        $totalLimit = array_key_exists('usage_limit_total', $data) ? $data['usage_limit_total'] : ($creating ? null : $voucher->getUsageLimitTotal());
        if (null !== $totalLimit && (false === filter_var($totalLimit, FILTER_VALIDATE_INT) || (int) $totalLimit < 1)) {
            return $this->json(['message' => 'usage_limit_total must be null or a positive integer.'], 422);
        }
        $customerLimit = array_key_exists('usage_limit_per_customer', $data) ? $data['usage_limit_per_customer'] : ($creating ? 1 : $voucher->getUsageLimitPerCustomer());
        if (false === filter_var($customerLimit, FILTER_VALIDATE_INT) || (int) $customerLimit < 1) {
            return $this->json(['message' => 'usage_limit_per_customer must be a positive integer.'], 422);
        }
        $starts = $this->date($data['starts_at'] ?? ($creating ? null : $voucher->getStartsAt()));
        $ends = $this->date($data['ends_at'] ?? ($creating ? null : $voucher->getEndsAt()));
        if (false === $starts || false === $ends || ($starts instanceof \DateTimeImmutable && $ends instanceof \DateTimeImmutable && $ends < $starts)) {
            return $this->json(['message' => 'starts_at and ends_at must be valid dates, with ends_at after starts_at.'], 422);
        }
        $active = array_key_exists('is_active', $data) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : ($creating || $voucher->isActive());
        if (null === $active) {
            return $this->json(['message' => 'is_active must be boolean.'], 422);
        }

        $voucher->setCode($code)->setType($type)->setValue(Voucher::TYPE_FREE_DELIVERY === $type ? null : $value)
            ->setMinOrderAmount($minimum)->setUsageLimitTotal(null === $totalLimit ? null : (int) $totalLimit)
            ->setUsageLimitPerCustomer((int) $customerLimit)->setStartsAt($starts instanceof \DateTimeImmutable ? $starts : null)
            ->setEndsAt($ends instanceof \DateTimeImmutable ? $ends : null)->setIsActive($active);

        return null;
    }

    private function date(mixed $value): \DateTimeImmutable|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Voucher $voucher): array
    {
        return [
            'id' => $voucher->getId(), 'code' => $voucher->getCode(), 'scope' => $voucher->getScope(),
            'type' => $voucher->getType(), 'value' => $voucher->getValue(), 'min_order_amount' => $voucher->getMinOrderAmount(),
            'usage_limit_total' => $voucher->getUsageLimitTotal(), 'usage_limit_per_customer' => $voucher->getUsageLimitPerCustomer(),
            'redemption_count' => $voucher->getRedemptions()->count(), 'starts_at' => $voucher->getStartsAt()?->format(\DateTimeInterface::ATOM),
            'ends_at' => $voucher->getEndsAt()?->format(\DateTimeInterface::ATOM), 'is_active' => $voucher->isActive(),
        ];
    }

    /**
     * Accepts a plain decimal (no exponent or spaces; a leading minus only when $allowNegative)
     * and returns it rounded half-up to two places, or null when the value is not such a number.
     */
    private function decimalInput(mixed $value, bool $allowNegative = false): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        $pattern = $allowNegative ? '/^-?\d{1,8}(\.\d+)?$/' : '/^\d{1,8}(\.\d+)?$/';
        if (!is_string($value) || 1 !== preg_match($pattern, trim($value))) {
            return null;
        }

        return bcround(trim($value), 2);
    }
}
