<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\ScopedClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetClientCaseCollectionQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ScopedClientCaseReadModelFactoryInterface $cases,
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private PropertyCatalogInterface $catalog,
        private \App\Security\SalesRequestScope $scope,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(GetClientCaseCollectionQuery $query): array
    {
        $organizationId = $query->organizationId->value();
        $owner=$this->scope->ownerFor($organizationId,$query->filters['assigned_user_id']??null);
        $read=$this->cases->forOwner($organizationId,$owner);
        $filters = $read->filters($query->filters);

        $result = [
            'filters' => $filters,
            'cases' => $read->cases($filters),
            'stats' => $read->stats(),
            'unlinked_requests' => $read->unlinkedInboundRequests(),
            'open_cases' => $read->openCaseOptions(),
            'managers' => $this->scope->ownerOptions($organizationId,$read->managerOptions()),
            'property_types' => $this->catalog->propertyTypes(),
            'locations' => $this->catalog->locations(),
            'pipelines' => $this->sales->pipelines($organizationId),
        ];
        if ($this->scope->ownerFor($organizationId,$query->filters['assigned_user_id']??null)!==$owner) throw new \DomainException('Sales scope changed.');
        return $result;
    }
}
