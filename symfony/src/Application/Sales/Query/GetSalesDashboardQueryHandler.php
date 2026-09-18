<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesDashboardQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales)
    {
    }

    public function __invoke(GetSalesDashboardQuery $query): array
    {
        return $this->sales->dashboard($query->organizationId->value(), $query->ownerId);
    }
}
