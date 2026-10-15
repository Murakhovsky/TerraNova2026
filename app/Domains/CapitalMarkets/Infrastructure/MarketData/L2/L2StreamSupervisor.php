<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Prevents publication of stale books and bounds recovery attempts. */
final class L2StreamSupervisor
{
    private int $recoveryEpoch = 0;
    private string $state = 'STOPPED';
    private int $attempts = 0;
    private ?string $lastError = null;
    public function __construct(private readonly L2ReconnectPolicy $policy) {}

    public function start(): int
    {
        if (!in_array($this->state, ['STOPPED','BACKOFF'], true)) throw new DomainException('Stream already active.');
        ++$this->recoveryEpoch;
        $this->state = 'CONNECTING';
        return $this->recoveryEpoch;
    }

    public function connected(int $epoch): void
    {
        $this->assertEpoch($epoch);
        if ($this->state !== 'CONNECTING') throw new DomainException('Stream not connecting.');
        $this->state = 'SYNCING';
    }

    public function verified(int $epoch): void
    {
        $this->assertEpoch($epoch);
        if ($this->state !== 'SYNCING') throw new DomainException('Stream not synchronized.');
        $this->state = 'TRUSTED';
        $this->attempts = 0;
        $this->lastError = null;
    }

    public function failure(int $epoch, string $reason): int
    {
        $this->assertEpoch($epoch);
        if ($this->state === 'STOPPED') throw new DomainException('Stream stopped.');
        $this->state = 'BACKOFF';
        $this->lastError = $reason;
        ++$this->attempts;
        return $this->policy->delayMs($this->attempts);
    }

    public function stop(): void
    {
        ++$this->recoveryEpoch;
        $this->state = 'STOPPED';
        $this->attempts = 0;
    }

    public function mayPublish(int $epoch): bool
    {
        return $epoch === $this->recoveryEpoch && $this->state === 'TRUSTED';
    }

    public function diagnostics(): array
    {
        return ['state'=>$this->state,'epoch'=>$this->recoveryEpoch,'attempts'=>$this->attempts,'error'=>$this->lastError];
    }

    private function assertEpoch(int $epoch): void
    {
        if ($epoch !== $this->recoveryEpoch) throw new DomainException('Stale stream epoch.');
    }
}
