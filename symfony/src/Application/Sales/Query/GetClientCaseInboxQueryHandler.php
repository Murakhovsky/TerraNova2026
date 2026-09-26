<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetClientCaseInboxQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ClientCaseReadModelFactoryInterface $cases)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetClientCaseInboxQuery $query): array
    {
        $read = $this->cases->forOrganization($query->organizationId->value());
        $filters = $read->inboundFilters($query->filters);

        return [
            'filters' => $filters,
            'requests' => $read->inboundInbox($filters),
            'stats' => $read->inboundInboxStats(),
            'open_cases' => $read->openCaseOptions(),
            'managers' => $read->managerOptions(),
        ];
    }
}
