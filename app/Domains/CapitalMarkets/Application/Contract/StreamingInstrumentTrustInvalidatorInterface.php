<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

/**
 * Fenced per-instrument trust revocation for multiplexed market-data sources.
 * This must not degrade unrelated instruments or source transport health.
 */
interface StreamingInstrumentTrustInvalidatorInterface
{
    public function invalidateInstrument(
        string $organizationId,
        string $sourceId,
        string $shard,
        string $workerId,
        int $fencingToken,
        string $instrumentId,
        string $reason,
    ):int;
}
