<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesDealsCollectionQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private SalesTeamAdministrationInterface $teams,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetSalesDealsCollectionQuery $query): array
    {
        $page = max(1, $query->page);
        $perPage = max(10, min(200, $query->perPage));
        $filters = array_intersect_key($query->filters, array_flip([
            'owner_id',
            'priority',
            'source',
            'pipeline_id',
            'stage_id',
            'risk',
        ]));
        if ($query->search !== '') {
            $filters['q'] = $query->search;
        }
        $filters['limit'] = $perPage + 1;
        $filters['offset'] = ($page - 1) * $perPage;

        $items = $this->sales->deals($query->organizationId->value(), $filters);
        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            $items = array_slice($items, 0, $perPage);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
            ],
            'pipelines' => $this->sales->pipelines($query->organizationId->value()),
            'owners' => $this->teams->users($query->organizationId->value()),
        ];
    }
}
