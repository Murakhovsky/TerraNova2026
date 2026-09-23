<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetSalesDirectorDashboardQuery implements QueryInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $historyDays = 30,
        public int $forecastDays = 30,
        public ?string $pipelineId = null,
    ) {
    }
}
