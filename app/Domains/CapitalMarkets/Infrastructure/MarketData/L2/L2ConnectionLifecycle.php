<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Fail-closed subscription lifecycle with monotonic epochs and reconnect budget. */
final class L2ConnectionLifecycle
{
    private string $state = 'STOPPED';
    private int $epoch = 0;
    private int $failures = 0;
    private ?string $failure = null;

    public function __construct(private readonly L2ReconnectPolicy $policy) {}

    public function start(): int
    {
        if ($this->state !== 'STOPPED' && $this->state !== 'BACKOFF') throw new DomainException('L2 connection already active.');
        ++$this->epoch;
        $this->state = 'CONNECTING';
        $this->failure = null;
        return $this->epoch;
    }

    public function subscribed(int $epoch): void
    {
        $this->requireEpoch($epoch);
        if ($this->state !== 'CONNECTING') throw new DomainException('Unexpected subscription acknowledgement.');
        $this->state = 'SYNCING';
    }

    public function trusted(int $epoch): void
    {
        $this->requireEpoch($epoch);
        if ($this->state !== 'SYNCING') throw new DomainException('Cannot trust unsynchronized stream.');
        $this->state = 'TRUSTED';
        $this->failures = 0;
    }

    public function failed(int $epoch, string $reason): int
    {
        $this->requireEpoch($epoch);
        if ($this->state === 'STOPPED') throw new DomainException('Stopped L2 connection.');
        $this->state = 'BACKOFF';
        $this->failure = $reason;
        ++$this->failures;
        return $this->policy->delayMs($this->failures);
    }

    public function stop(): void
    {
        ++$this->epoch;
        $this->state = 'STOPPED';
        $this->failure = null;
        $this->failures = 0;
    }

    public function isTrusted(int $epoch): bool { return $epoch === $this->epoch && $this->state === 'TRUSTED'; }

    public function diagnostics(): array
    {
        return ['state' => $this->state, 'epoch' => $this->epoch, 'failures' => $this->failures, 'failure' => $this->failure];
    }

    private function requireEpoch(int $epoch): void
    {
        if ($epoch !== $this->epoch) throw new DomainException('Stale L2 connection epoch.');
    }
}
