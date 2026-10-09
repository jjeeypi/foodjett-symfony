<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

final class ApiPaginator
{
    /**
     * @template T of object
     *
     * @param QueryBuilder             $queryBuilder
     * @param callable(T): array<mixed> $normalize
     *
     * @return array{data: list<array<mixed>>, meta: array{page: int, perPage: int, total: int, lastPage: int}}
     */
    public function paginate(QueryBuilder $queryBuilder, Request $request, callable $normalize): array
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('perPage', 25)));
        $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $paginator = new Paginator($queryBuilder->getQuery(), true);
        $total = count($paginator);
        $data = [];
        foreach ($paginator as $entity) {
            $data[] = $normalize($entity);
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
