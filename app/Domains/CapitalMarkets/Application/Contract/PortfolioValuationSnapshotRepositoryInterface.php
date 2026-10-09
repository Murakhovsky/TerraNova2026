<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use DateTimeImmutable;

interface PortfolioValuationSnapshotRepositoryInterface
{
    /** Only a governed portfolio valuation producer may call this API.
     *  @param array<string,mixed> $snapshot
     */
    public function append(string $organizationId,string $portfolioId,array $snapshot):void;

    /** @return list<array<string,mixed>> */
    public function history(string $organizationId,string $portfolioId,DateTimeImmutable $from,DateTimeImmutable $to):array;
}
