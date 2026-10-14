<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Bounded reconnect delay policy. Transport callers must rotate the recovery
 * epoch before reconnecting and must never publish stale order books.
 */
final class L2ReconnectPolicy
{
    public function __construct(
        private readonly int $baseDelayMs = 500,
        private readonly int $maxDelayMs = 30000,
        private readonly int $maxAttempts = 20,
    ) {
        if ($baseDelayMs < 1 || $maxDelayMs < $baseDelayMs || $maxAttempts < 1) {
            throw new DomainException('Invalid L2 reconnect configuration.');
        }
    }

    public function delayMs(int $attempt): int
    {
        if ($attempt < 1 || $attempt > $this->maxAttempts) {
            throw new DomainException('L2 reconnect attempt budget exhausted.');
        }
        $delay = $this->baseDelayMs;
        for ($i = 1; $i < $attempt; ++$i) {
            if ($delay >= $this->maxDelayMs) break;
            $delay = min($this->maxDelayMs, $delay > intdiv(PHP_INT_MAX, 2) ? $this->maxDelayMs : $delay * 2);
        }
        return $delay;
    }

    public function maxAttempts(): int { return $this->maxAttempts; }
}
