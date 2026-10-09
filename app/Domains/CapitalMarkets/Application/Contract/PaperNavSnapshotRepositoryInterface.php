<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use DateTimeImmutable;

interface PaperNavSnapshotRepositoryInterface
{
    /** @param array<string,mixed> $snapshot SIMULATED only, never certified */
    public function append(string $organizationId,string $portfolioId,array $snapshot):void;

    /** @return list<array<string,mixed>> A complete, non-truncated paper valuation window. */
    public function history(string $organizationId,string $portfolioId,DateTimeImmutable $from,DateTimeImmutable $to):array;
}
