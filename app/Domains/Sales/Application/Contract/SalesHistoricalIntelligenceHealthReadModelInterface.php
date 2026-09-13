<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesHistoricalIntelligenceHealthReadModelInterface
{
    /** @return array<string,mixed> */
    public function snapshot(string $organizationId): array;
}
