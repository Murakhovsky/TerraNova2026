<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Throwable;

final readonly class GetClientCaseWorkspaceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ClientCaseReadModelFactoryInterface $cases,
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private PropertyCatalogInterface $catalog,
        private OperationsReadModelInterface $operations,
    ) {}

    /** @return array<string,mixed>|null */
    public function __invoke(GetClientCaseWorkspaceQuery $query): ?array
    {
        $organizationId = $query->organizationId->value();
        $read = $this->cases->forOrganization($organizationId);
        $case = $read->case($query->caseId);

        if ($case === null) {
            return null;
        }

        $intelligence = [];
        try {
            $intelligence = $this->operations->dealIntelligence($organizationId, $query->caseId);
        } catch (Throwable) {
            // Intelligence is optional; core Client Case work must remain available without it.
        }

        $pipelines = $this->sales->pipelines($organizationId);

        return [
            'case' => $case,
            'inbound_requests' => $read->inboundRequests($query->caseId),
            'activities' => $read->activities($query->caseId),
            'property_matches' => $read->propertyMatches($query->caseId),
            'request_matches' => $read->requestMatches($query->caseId),
            'managers' => $read->managerOptions(),
            'property_types' => $this->catalog->propertyTypes(),
            'locations' => $this->catalog->locations(),
            'pipeline_stages' => is_array($pipelines[0]['stages'] ?? null) ? $pipelines[0]['stages'] : [],
            'intelligence' => is_array($intelligence) ? $intelligence : [],
        ];
    }
}
