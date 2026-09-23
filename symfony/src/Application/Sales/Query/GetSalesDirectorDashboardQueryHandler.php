<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesDirectorCockpitService;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesDirectorDashboardQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesDirectorCockpitService $director)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetSalesDirectorDashboardQuery $query): array
    {
        return $this->director->overview(
            $query->organizationId->value(),
            new DateTimeImmutable(),
            max(1, min(366, $query->historyDays)),
            max(1, min(366, $query->forecastDays)),
            $query->pipelineId !== null && trim($query->pipelineId) !== ''
                ? trim($query->pipelineId)
                : null,
        );
    }
}
