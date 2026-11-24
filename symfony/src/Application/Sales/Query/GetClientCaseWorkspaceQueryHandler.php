<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\ScopedClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Throwable;

final readonly class GetClientCaseWorkspaceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ScopedClientCaseReadModelFactoryInterface $cases,
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private PropertyCatalogInterface $catalog,
        private OperationsReadModelInterface $operations,
        private \App\Security\SalesRequestScope $scope,
    ) {}

    /** @return array<string,mixed>|null */
    public function __invoke(GetClientCaseWorkspaceQuery $query): ?array
    {
        $organizationId = $query->organizationId->value();
        if (!$this->scope->entity($organizationId,'deal',$query->caseId)) return null;
        $caseProbe=$this->cases->forOwner($organizationId,null)->case($query->caseId);
        if ($caseProbe===null) return null;
        $owner=$this->scope->searchOwner($organizationId)===null?null:$this->scope->ownerFor($organizationId,$caseProbe['assigned_user_id']??null);
        $read=$this->cases->forOwner($organizationId,$owner);
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

        $result = [
            'case' => $case,
            'inbound_requests' => $read->inboundRequests($query->caseId),
            'activities' => $read->activities($query->caseId),
            'property_matches' => $read->propertyMatches($query->caseId),
            'request_matches' => $read->requestMatches($query->caseId),
            'managers' => $this->scope->ownerOptions($organizationId,$read->managerOptions()),
            'property_types' => $this->catalog->propertyTypes(),
            'locations' => $this->catalog->locations(),
            'pipeline_stages' => is_array($pipelines[0]['stages'] ?? null) ? $pipelines[0]['stages'] : [],
            'intelligence' => is_array($intelligence) ? $intelligence : [],
        ];
        if (!$this->scope->entity($organizationId,'deal',$query->caseId)||($owner===null&&$this->scope->searchOwner($organizationId)!==null)) return null;
        if ($owner!==null&&$read->case($query->caseId)===null) return null;
        return $result;
    }
}
