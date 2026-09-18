<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesPipelinesQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales)
    {
    }

    public function __invoke(GetSalesPipelinesQuery $query): array
    {
        return $this->sales->pipelines($query->organizationId->value());
    }
}
