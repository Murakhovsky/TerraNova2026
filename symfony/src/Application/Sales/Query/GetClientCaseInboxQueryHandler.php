<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\ScopedClientCaseReadModelFactoryInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetClientCaseInboxQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ScopedClientCaseReadModelFactoryInterface $cases,private \App\Security\SalesRequestScope $scope)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetClientCaseInboxQuery $query): array
    {
        $org=$query->organizationId->value();
        $owner=$this->scope->ownerFor($org,$query->filters['assigned_user_id']??null);
        $read=$this->cases->forOwner($org,$owner);
        $filters = $read->inboundFilters($query->filters);

        $result = [
            'filters' => $filters,
            'requests' => $read->inboundInbox($filters),
            'stats' => $read->inboundInboxStats(),
            'open_cases' => $read->openCaseOptions(),
            'managers' => $this->scope->ownerOptions($org,$read->managerOptions()),
        ];
        if ($this->scope->ownerFor($org,$query->filters['assigned_user_id']??null)!==$owner) throw new \DomainException('Sales scope changed.');
        return $result;
    }
}
