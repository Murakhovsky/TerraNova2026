<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Per-stream operational counters. No credentials or raw market payloads are logged.
 * Intended for export by a CLI supervisor / metrics endpoint.
 */
final class L2OperationalMetrics
{
    private int $connections = 0;
    private int $reconnects = 0;
    private int $publications = 0;
    private int $failures = 0;
    private ?string $lastFailure = null;
    private ?int $lastPublicationAt = null;

    public function connection(int $attempt): void
    {
        if ($attempt < 1) throw new DomainException('Invalid connection attempt.');
        ++$this->connections;
        if ($attempt > 1) ++$this->reconnects;
    }

    public function publication(?int $unixSeconds = null): void
    {
        $time = $unixSeconds ?? time();
        if ($time < 0 || ($this->lastPublicationAt !== null && $time < $this->lastPublicationAt)) {
            throw new DomainException('Invalid publication timestamp.');
        }
        ++$this->publications;
        $this->lastPublicationAt = $time;
    }

    public function failure(string $reason): void
    {
        ++$this->failures;
        $this->lastFailure = substr($reason, 0, 200);
    }

    public function snapshot(?int $now = null, int $staleAfterSeconds = 30): array
    {
        if ($staleAfterSeconds < 1) throw new DomainException('Invalid freshness threshold.');
        $now ??= time();
        return [
            'connections' => $this->connections,
            'reconnects' => $this->reconnects,
            'publications' => $this->publications,
            'failures' => $this->failures,
            'last_failure' => $this->lastFailure,
            'last_publication_at' => $this->lastPublicationAt,
            'stale' => $this->lastPublicationAt === null || $now < $this->lastPublicationAt
                || $now - $this->lastPublicationAt > $staleAfterSeconds,
        ];
    }
}
