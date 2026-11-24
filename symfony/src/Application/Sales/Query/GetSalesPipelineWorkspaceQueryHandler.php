<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use App\Security\SalesRequestScope;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesPipelineWorkspaceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private SalesTeamAdministrationInterface $teams,
        private SalesRequestScope $scope,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetSalesPipelineWorkspaceQuery $query): array
    {
        $filters = array_intersect_key($query->filters, array_flip([
            'pipeline_id',
            'owner_id',
            'risk',
            'priority',
            'value_min',
            'value_max',
            'source',
            'q',
        ]));
        $filters['limit'] = 100;
        $org=$query->organizationId->value();
        $owner=$this->scope->ownerFor($org,$filters['owner_id']??null);
        $filters['owner_id']=$owner;

        return [
            'pipelines' => $this->sales->pipelines($org,$owner),
            'deals' => $this->sales->deals($query->organizationId->value(), $filters),
            'owners' => $this->scope->ownerOptions($org,$this->teams->users($org)),
        ];
    }
}
