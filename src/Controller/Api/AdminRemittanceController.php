<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Admin;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use App\Entity\User;
use App\Enum\RemittanceStatus;
use App\Service\ApiPaginator;
use App\Service\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/rider-cash-remittances', name: 'api_admin_rider_cash_remittances_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminRemittanceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPaginator $paginator,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $this->entityManager->getRepository(RiderCashRemittance::class)->createQueryBuilder('remittance')
            ->innerJoin('remittance.rider', 'rider')->addSelect('rider')->innerJoin('rider.user', 'riderUser')->addSelect('riderUser')
            ->leftJoin('remittance.confirmedByAdmin', 'admin')->addSelect('admin')
            ->orderBy('remittance.createdAt', 'ASC');
        if ('' !== ($statusValue = trim((string) $request->query->get('status', '')))) {
            $status = RemittanceStatus::tryFrom($statusValue);
            if (null === $status) { return $this->json(['message' => 'Invalid status.'], 422); }
            $query->andWhere('remittance.status = :status')->setParameter('status', $status->value);
        }

        return $this->json($this->paginator->paginate($query, $request, fn (RiderCashRemittance $remittance): array => $this->normalize($remittance)));
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(RiderCashRemittance $remittance): JsonResponse
    {
        $adminUser = $this->getUser();
        if (!$adminUser instanceof User || !$adminUser->getAdmin() instanceof Admin) { throw $this->createAccessDeniedException(); }
        try {
            /** @var RiderCashRemittance $updated */
            $updated = $this->entityManager->wrapInTransaction(function () use ($remittance, $adminUser): RiderCashRemittance {
                $locked = $this->entityManager->find(RiderCashRemittance::class, $remittance->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$locked instanceof RiderCashRemittance) { throw new \RuntimeException('Remittance not found.'); }
                if (RemittanceStatus::CONFIRMED === $locked->getStatus()) { return $locked; }
                $rider = $this->entityManager->find(Rider::class, $locked->getRider()->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$rider instanceof Rider) { throw new \RuntimeException('Rider not found.'); }
                if ((float) $locked->getAmount() > (float) $rider->getCashOnHand()) {
                    throw new \DomainException("The rider's cash balance is lower than this remittance amount.");
                }
                $beforeCash = $rider->getCashOnHand();
                $now = new \DateTimeImmutable();
                $afterCash = number_format(round((float) $beforeCash - (float) $locked->getAmount(), 2), 2, '.', '');
                $locked->setStatus(RemittanceStatus::CONFIRMED)->setConfirmedByAdmin($adminUser->getAdmin())
                    ->setRemittedAt($locked->getRemittedAt() ?? $now)->setUpdatedAt($now);
                $rider->setCashOnHand($afterCash)->setUpdatedAt($now);
                $this->audit->record($adminUser, 'rider_remittance.confirmed', 'rider_cash_remittance', (string) $locked->getId(), [
                    'status' => ['from' => RemittanceStatus::PENDING->value, 'to' => RemittanceStatus::CONFIRMED->value],
                    'rider_cash_on_hand' => ['from' => $beforeCash, 'to' => $afterCash],
                    'amount' => $locked->getAmount(),
                ]);
                $this->entityManager->flush();
                return $locked;
            });
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json(['remittance' => $this->normalize($updated)]);
    }

    /** @return array<string, mixed> */
    private function normalize(RiderCashRemittance $remittance): array
    {
        return [
            'id' => $remittance->getId(),
            'rider' => ['id' => $remittance->getRider()->getId(), 'name' => $remittance->getRider()->getUser()->getName(), 'cash_on_hand' => $remittance->getRider()->getCashOnHand()],
            'amount' => $remittance->getAmount(), 'reference_note' => $remittance->getReferenceNote(), 'status' => $remittance->getStatus()->value,
            'confirmed_by_admin_id' => $remittance->getConfirmedByAdmin()?->getId(), 'remitted_at' => $remittance->getRemittedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $remittance->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
