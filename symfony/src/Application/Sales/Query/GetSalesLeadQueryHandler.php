<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesLeadQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales)
    {
    }

    public function __invoke(GetSalesLeadQuery $query): ?array
    {
        return $this->sales->lead($query->organizationId->value(), $query->leadId);
    }
}
