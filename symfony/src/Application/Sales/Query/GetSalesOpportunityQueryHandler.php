<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesOpportunityQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales)
    {
    }

    public function __invoke(GetSalesOpportunityQuery $query): ?array
    {
        $organizationId = $query->organizationId->value();
        $opportunity = $this->sales->deal($organizationId, $query->opportunityId);
        if ($opportunity === null) return null;

        return [
            'opportunity' => $opportunity,
            'timeline' => $this->sales->timeline($organizationId, $query->opportunityId, 100),
        ];
    }
}
