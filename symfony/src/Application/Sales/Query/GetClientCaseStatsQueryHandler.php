<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetClientCaseStatsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ClientCaseReadModelFactoryInterface $readModels,
        private ?\Domains\Sales\Application\Contract\ScopedClientCaseReadModelFactoryInterface $scoped=null,
        private ?\App\Security\SalesRequestScope $scope=null)
    {
    }

    /** @return array{organization_id:string,stats:array<string,int>} */
    public function __invoke(GetClientCaseStatsQuery $query): array
    {
        $org=$query->organizationId->value();
        if ($query->actorId!==null) {
            if ($this->scope===null||$this->scoped===null||$this->scope->actor($org)!==$query->actorId) throw new \DomainException('Sales scope denied.');
            $owner=$this->scope->searchOwner($org);
            $stats=$this->scoped->forOwner($org,$owner)->stats();
            if ($this->scope->searchOwner($org)!==$owner) throw new \DomainException('Sales scope changed.');
            return ['organization_id'=>$org,'stats'=>$stats];
        }
        // Explicit trusted CLI maintenance path, not used by HTTP.
        return [
            'organization_id' => $query->organizationId->value(),
            'stats' => $this->readModels
                ->forOrganization($query->organizationId->value())
                ->stats(),
        ];
    }
}
