<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Order;
use App\Entity\Rider;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractRiderController extends AbstractController
{
    public function __construct(protected readonly EntityManagerInterface $entityManager)
    {
    }

    protected function rider(): Rider
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->getRider() instanceof Rider) {
            throw $this->createNotFoundException('Rider profile not found.');
        }

        return $user->getRider();
    }

    protected function riderUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    protected function activeOrder(bool $required = true): ?Order
    {
        $terminal = array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isTerminal())),
        );
        $order = $this->entityManager->getRepository(Order::class)->createQueryBuilder('orders')
            ->andWhere('orders.rider = :rider')->setParameter('rider', $this->rider())
            ->andWhere('orders.status NOT IN (:terminal)')->setParameter('terminal', $terminal)
            ->orderBy('orders.riderAssignedAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if ($required && !$order instanceof Order) {
            throw $this->createNotFoundException('No active order.');
        }

        return $order instanceof Order ? $order : null;
    }

    /** @return array<string, mixed>|JsonResponse */
    protected function body(Request $request): array|JsonResponse
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            try {
                return $request->toArray();
            } catch (\Throwable) {
                return $this->json(['message' => 'The request body must contain valid JSON.'], 400);
            }
        }

        return $request->request->all();
    }

    /** @param array<string, mixed> $data
     * @return array{0: ?string, 1: ?string, 2: array<string, list<string>>}
     */
    protected function coordinates(array $data, bool $required): array
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $errors = [];
        if ($required || null !== $latitude || null !== $longitude) {
            if (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90) {
                $errors['latitude'][] = 'Latitude must be numeric and between -90 and 90.';
            }
            if (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180) {
                $errors['longitude'][] = 'Longitude must be numeric and between -180 and 180.';
            }
        }

        return [
            [] === $errors && null !== $latitude ? number_format((float) $latitude, 7, '.', '') : null,
            [] === $errors && null !== $longitude ? number_format((float) $longitude, 7, '.', '') : null,
            $errors,
        ];
    }
}
