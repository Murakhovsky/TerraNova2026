<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;

interface SalesForecastRiskReadModelInterface
{
    /** @return list<array<string,mixed>> */
    public function openDeals(
        string $organizationId,
        DateTimeImmutable $asOf,
        ?string $pipelineId = null,
    ): array;
}
