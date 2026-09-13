<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesDealStageHistoryReadModelInterface
{
    /** @return list<array<string, mixed>> */
    public function dealHistory(string $organizationId, string $dealId): array;

    /** @return array{COMPLETE:int,PARTIAL:int,ESTIMATED:int} */
    public function qualitySummary(string $organizationId): array;
}
