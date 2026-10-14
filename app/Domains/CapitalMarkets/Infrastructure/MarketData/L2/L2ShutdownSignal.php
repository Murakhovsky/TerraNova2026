<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/**
 * Process-level stop token. Signal handlers only flip a boolean;
 * no socket, DB or logging operations execute inside signal context.
 */
final class L2ShutdownSignal
{
    private bool $stopRequested = false;

    public function requestStop(): void { $this->stopRequested = true; }

    public function shouldStop(): bool { return $this->stopRequested; }

    /** Register SIGTERM/SIGINT for a long-running CLI worker. */
    public function install(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('pcntl_signal')) {
            throw new DomainException('CLI pcntl extension is required for signal handling.');
        }
        if (function_exists('pcntl_async_signals')) pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $this->requestStop(...));
        pcntl_signal(SIGINT, $this->requestStop(...));
    }
}
