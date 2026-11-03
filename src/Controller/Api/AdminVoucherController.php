<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Voucher;
use App\Enum\VoucherScope;
use App\Enum\VoucherType;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/vouchers', name: 'api_admin_vouchers_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminVoucherController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ApiPaginator $paginator, private readonly AuditLogger $audit)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(Voucher::class)->createQueryBuilder('voucher')
            ->andWhere('voucher.scope = :scope')->setParameter('scope', VoucherScope::PLATFORM->value)
            ->orderBy('voucher.createdAt', 'DESC');
        return $this->json($this->paginator->paginate($query, $request, fn (Voucher $voucher): array => $this->normalize($voucher)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) { return $body; }
        $voucher = new Voucher();
        $result = $this->apply($voucher, $body, true);
        if ($result instanceof JsonResponse) { return $result; }
        $now = new \DateTimeImmutable();
        $voucher->setScope(VoucherScope::PLATFORM)->setRestaurant(null)->setCreatedAt($now)->setUpdatedAt($now);
        $this->entityManager->persist($voucher);
        $this->entityManager->flush();
        $this->audit->record($this->admin(), 'voucher.created', 'voucher', (string) $voucher->getId(), $this->normalize($voucher));
        $this->entityManager->flush();
        return $this->json(['voucher' => $this->normalize($voucher)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(Voucher $voucher, Request $request): JsonResponse
    {
        if (VoucherScope::PLATFORM !== $voucher->getScope()) { throw $this->createNotFoundException(); }
        $body = $this->body($request);
        if ($body instanceof JsonResponse) { return $body; }
        $before = $this->normalize($voucher);
        $result = $this->apply($voucher, $body, false);
        if ($result instanceof JsonResponse) { return $result; }
        $voucher->setUpdatedAt(new \DateTimeImmutable());
        $this->audit->record($this->admin(), 'voucher.updated', 'voucher', (string) $voucher->getId(), ['before' => $before, 'after' => $this->normalize($voucher)]);
        $this->entityManager->flush();
        return $this->json(['voucher' => $this->normalize($voucher)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(Voucher $voucher): JsonResponse
    {
        if (VoucherScope::PLATFORM !== $voucher->getScope()) { throw $this->createNotFoundException(); }
        if (!$voucher->getRedemptions()->isEmpty()) {
            return $this->json(['message' => 'A redeemed voucher cannot be deleted; deactivate it instead.'], 409);
        }
        $id = (string) $voucher->getId();
        $this->audit->record($this->admin(), 'voucher.deleted', 'voucher', $id, $this->normalize($voucher));
        $this->entityManager->remove($voucher);
        $this->entityManager->flush();
        return $this->json(null, 204);
    }

    /** @param array<string, mixed> $data */
    private function apply(Voucher $voucher, array $data, bool $creating): true|JsonResponse
    {
        $code = array_key_exists('code', $data) ? mb_strtoupper(trim((string) $data['code'])) : ($creating ? '' : $voucher->getCode());
        if ('' === $code || mb_strlen($code) > 255) { return $this->json(['message' => 'code is required and cannot exceed 255 characters.'], 422); }
        $duplicate = $this->entityManager->getRepository(Voucher::class)->findOneBy(['code' => $code]);
        if ($duplicate instanceof Voucher && $duplicate->getId() !== $voucher->getId()) { return $this->json(['message' => 'A voucher with that code already exists.'], 409); }
        $type = array_key_exists('type', $data) ? VoucherType::tryFrom((string) $data['type']) : ($creating ? null : $voucher->getType());
        if (null === $type) { return $this->json(['message' => 'Invalid or missing type.'], 422); }
        $rawValue = array_key_exists('value', $data) ? $data['value'] : ($creating ? null : $voucher->getValue());
        $value = null === $rawValue || '' === $rawValue ? null : (is_numeric($rawValue) ? (float) $rawValue : null);
        if (VoucherType::FREE_DELIVERY !== $type && (null === $value || $value <= 0)) { return $this->json(['message' => 'value must be greater than zero for percentage and fixed vouchers.'], 422); }
        if (VoucherType::PERCENTAGE === $type && $value > 100) { return $this->json(['message' => 'Percentage value cannot exceed 100.'], 422); }
        $minimum = array_key_exists('min_order_amount', $data) ? $data['min_order_amount'] : ($creating ? 0 : $voucher->getMinOrderAmount());
        if (!is_numeric($minimum) || (float) $minimum < 0) { return $this->json(['message' => 'min_order_amount must be non-negative.'], 422); }
        $totalLimit = array_key_exists('usage_limit_total', $data) ? $data['usage_limit_total'] : ($creating ? null : $voucher->getUsageLimitTotal());
        if (null !== $totalLimit && (false === filter_var($totalLimit, FILTER_VALIDATE_INT) || (int) $totalLimit < 1)) { return $this->json(['message' => 'usage_limit_total must be null or a positive integer.'], 422); }
        $customerLimit = array_key_exists('usage_limit_per_customer', $data) ? $data['usage_limit_per_customer'] : ($creating ? 1 : $voucher->getUsageLimitPerCustomer());
        if (false === filter_var($customerLimit, FILTER_VALIDATE_INT) || (int) $customerLimit < 1) { return $this->json(['message' => 'usage_limit_per_customer must be a positive integer.'], 422); }
        $starts = $this->date($data['starts_at'] ?? ($creating ? null : $voucher->getStartsAt()));
        $ends = $this->date($data['ends_at'] ?? ($creating ? null : $voucher->getEndsAt()));
        if (false === $starts || false === $ends || ($starts instanceof \DateTimeImmutable && $ends instanceof \DateTimeImmutable && $ends < $starts)) {
            return $this->json(['message' => 'starts_at and ends_at must be valid dates, with ends_at after starts_at.'], 422);
        }
        $active = array_key_exists('is_active', $data) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : ($creating || $voucher->isActive());
        if (null === $active) { return $this->json(['message' => 'is_active must be boolean.'], 422); }
        $voucher->setCode($code)->setType($type)->setValue(VoucherType::FREE_DELIVERY === $type ? null : number_format((float) $value, 2, '.', ''))
            ->setMinOrderAmount(number_format((float) $minimum, 2, '.', ''))->setUsageLimitTotal(null === $totalLimit ? null : (int) $totalLimit)
            ->setUsageLimitPerCustomer((int) $customerLimit)->setStartsAt($starts instanceof \DateTimeImmutable ? $starts : null)
            ->setEndsAt($ends instanceof \DateTimeImmutable ? $ends : null)->setIsActive($active);
        return true;
    }

    private function date(mixed $value): \DateTimeImmutable|false|null
    {
        if (null === $value || '' === $value) { return null; }
        if ($value instanceof \DateTimeImmutable) { return $value; }
        try { return new \DateTimeImmutable((string) $value); } catch (\Throwable) { return false; }
    }

    /** @return array<string, mixed>|JsonResponse */
    private function body(Request $request): array|JsonResponse
    {
        try { return $request->toArray(); } catch (\Throwable) { return $this->json(['message' => 'The request body must contain valid JSON.'], 400); }
    }

    /** @return array<string, mixed> */
    private function normalize(Voucher $voucher): array
    {
        return ['id' => $voucher->getId(), 'code' => $voucher->getCode(), 'scope' => $voucher->getScope()->value, 'type' => $voucher->getType()->value,
            'value' => $voucher->getValue(), 'min_order_amount' => $voucher->getMinOrderAmount(), 'usage_limit_total' => $voucher->getUsageLimitTotal(),
            'usage_limit_per_customer' => $voucher->getUsageLimitPerCustomer(), 'redemption_count' => $voucher->getRedemptions()->count(),
            'starts_at' => $voucher->getStartsAt()?->format(\DateTimeInterface::ATOM), 'ends_at' => $voucher->getEndsAt()?->format(\DateTimeInterface::ATOM), 'is_active' => $voucher->isActive()];
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}
