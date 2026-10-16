<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use DateTimeImmutable;

/**
 * Canonical COS/AIDA local CRM activity facts, not attempted Action receipts.
 * Excludes remote CRM-only tasks that have no local activity projection.
 */
interface SalesLocalTaskOutcomeReadModelInterface
{
    public function createdTaskCount(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int;
}
