<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesTodayQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceOperationalReadModelInterface $sales,
        private \Domains\Sales\Application\Contract\SalesTodayPopulationReadPort $population,
        private \Domains\Sales\Application\Contract\SalesTeamScopeInterface $teams,
        private \App\Security\SalesRequestScope $scope)
    {
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function __invoke(GetSalesTodayQuery $query): array
    {
        $org=$query->organizationId->value();$actor=$this->scope->actor($org);
        if ($query->ownerId!==0&&$query->ownerId!==$actor) throw new \DomainException('Sales Today scope denied.');
        $team=$query->ownerId===0;
        $resolve=fn()=>!$team?[$actor]:($this->scope->searchOwner($org)===null?null:$this->teams->ledMembers($org,$actor));
        $owners=$resolve();
        $result=$this->population->todayForOwners($org,$owners);
        // A team queue is not authorization to decide another user's approval.
        $result['needs_approval']=$this->sales->approvals($org,null,$actor,20);
        $result['ai_recommended']=[]; // S6 owns governed AI execution and disclosure.
        if ($resolve()!==$owners) throw new \DomainException('Sales Today scope changed.');
        return $result;
    }
}
