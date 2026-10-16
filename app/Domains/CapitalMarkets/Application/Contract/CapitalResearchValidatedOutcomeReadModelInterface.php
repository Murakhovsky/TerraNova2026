<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use DateTimeImmutable;

/** Tenant-scoped authoritative persisted metric over a UTC window. */
interface CapitalResearchValidatedOutcomeReadModelInterface
{
    public function count(string $organizationId, DateTimeImmutable $from, DateTimeImmutable $to): int;
}
