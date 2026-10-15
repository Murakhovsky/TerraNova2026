<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Converts worker state + publication freshness into actionable health states.
 * An untrusted, stalled or exhausted stream is never marked healthy.
 */
final readonly class L2HealthEvaluator
{
    public function __construct(private int $staleAfterSeconds = 30)
    {
        if ($staleAfterSeconds < 1 || $staleAfterSeconds > 3600) {
            throw new DomainException('Invalid L2 health threshold.');
        }
    }

    /** @param array<string,mixed> $supervisor @param array<string,mixed> $metrics */
    public function evaluate(array $supervisor, array $metrics, ?int $now = null): array
    {
        $state = $supervisor['state'] ?? null;
        $last = $metrics['last_publication_at'] ?? null;
        $now ??= time();
        if (!is_string($state) || !in_array($state,['STOPPED','CONNECTING','SYNCING','TRUSTED','BACKOFF'],true)) {
            throw new DomainException('Unknown L2 supervisor state.');
        }
        $stale = !is_int($last) || $last > $now || $now - $last > $this->staleAfterSeconds;
        $status = match (true) {
            $state === 'STOPPED' => 'STOPPED',
            $state === 'BACKOFF' => 'DEGRADED',
            $state !== 'TRUSTED' => 'RECOVERING',
            $stale => 'STALE',
            default => 'HEALTHY',
        };
        return [
            'status'=>$status,
            'trusted'=>$status === 'HEALTHY',
            'alert'=>in_array($status,['DEGRADED','STALE'],true),
            'reason'=>$supervisor['error'] ?? ($stale ? 'PUBLICATION_STALE' : null),
        ];
    }
}
