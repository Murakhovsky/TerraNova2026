<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetClientCaseCollectionQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ClientCaseReadModelFactoryInterface $cases,
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private PropertyCatalogInterface $catalog,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(GetClientCaseCollectionQuery $query): array
    {
        $organizationId = $query->organizationId->value();
        $read = $this->cases->forOrganization($organizationId);
        $filters = $read->filters($query->filters);

        return [
            'filters' => $filters,
            'cases' => $read->cases($filters),
            'stats' => $read->stats(),
            'unlinked_requests' => $read->unlinkedInboundRequests(),
            'open_cases' => $read->openCaseOptions(),
            'managers' => $read->managerOptions(),
            'property_types' => $this->catalog->propertyTypes(),
            'locations' => $this->catalog->locations(),
            'pipelines' => $this->sales->pipelines($organizationId),
        ];
    }
}
