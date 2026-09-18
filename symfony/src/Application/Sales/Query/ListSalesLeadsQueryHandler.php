<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class ListSalesLeadsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales)
    {
    }

    public function __invoke(ListSalesLeadsQuery $query): array
    {
        $page = max(1, min((int) ($query->filters['page'] ?? 1), 100000));
        $perPage = max(1, min((int) ($query->filters['per_page'] ?? 50), 100));
        $filters = array_intersect_key($query->filters, array_flip([
            'status', 'source', 'q', 'owner_id', 'sort', 'direction',
        ]));
        $filters['limit'] = $perPage + 1;
        $filters['offset'] = ($page - 1) * $perPage;

        $items = $this->sales->leads($query->organizationId->value(), $filters);
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
        ];
    }
}
