<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetClientCaseStatsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ClientCaseReadModelFactoryInterface $readModels)
    {
    }

    /** @return array{organization_id:string,stats:array<string,int>} */
    public function __invoke(GetClientCaseStatsQuery $query): array
    {
        return [
            'organization_id' => $query->organizationId->value(),
            'stats' => $this->readModels
                ->forOrganization($query->organizationId->value())
                ->stats(),
        ];
    }
}
