<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/** Tracks freshness of successfully published trusted L2 books. */
final class L2PublicationWatchdog
{
    private ?int $lastPublishedMs = null;
    private int $count = 0;

    public function __construct(private readonly int $maxSilenceMs = 30000)
    {
        if ($maxSilenceMs < 100 || $maxSilenceMs > 3600000) {
            throw new DomainException('Invalid publication freshness threshold.');
        }
    }

    public function published(int $timestampMs): void
    {
        if ($timestampMs < 0 || ($this->lastPublishedMs !== null && $timestampMs < $this->lastPublishedMs)) {
            throw new DomainException('Non-monotonic publication clock.');
        }
        $this->lastPublishedMs = $timestampMs;
        ++$this->count;
    }

    public function isStale(int $nowMs): bool
    {
        return $nowMs < 0 || $this->lastPublishedMs === null || $nowMs < $this->lastPublishedMs
            || $nowMs - $this->lastPublishedMs > $this->maxSilenceMs;
    }

    public function reset(): void { $this->lastPublishedMs = null; }

    public function diagnostics(): array
    {
        return ['last_published_ms'=>$this->lastPublishedMs,'publications'=>$this->count,'max_silence_ms'=>$this->maxSilenceMs];
    }
}
