<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use App\Security\SalesRequestScope;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Throwable;

final readonly class GetSalesDealWorkspaceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private SalesTeamAdministrationInterface $teams,
        private OperationsReadModelInterface $operations,
        private SalesRequestScope $scope,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(GetSalesDealWorkspaceQuery $query): ?array
    {
        $organizationId = $query->organizationId->value();
        if (!$this->scope->entity($organizationId,'deal',$query->dealId)) return null;
        $deal = $this->sales->deal($organizationId, $query->dealId);

        if ($deal === null) {
            return null;
        }

        $intelligence = [];
        try {
            $intelligence = $this->operations->dealIntelligence($organizationId, $query->dealId);
        } catch (Throwable) {
            // Intelligence is an optional read projection; the Deal Workspace remains usable without it.
        }

        $owner=$this->scope->searchOwner($organizationId);
        $result=[
            'deal' => $deal,
            'timeline' => $this->sales->timeline($organizationId, $query->dealId, 100),
            'communications' => $this->sales->communications($organizationId, $query->dealId, 50),
            'approvals' => $owner===null?$this->sales->approvals($organizationId, $query->dealId, null, 50):[],
            'pipelines' => $this->sales->pipelines($organizationId,$owner),
            'owners' => $this->scope->ownerOptions($organizationId,$this->teams->users($organizationId)),
            'intelligence' => is_array($intelligence) ? $intelligence : [],
        ];
        return $this->scope->entity($organizationId,'deal',$query->dealId)?$result:null;
    }
}
