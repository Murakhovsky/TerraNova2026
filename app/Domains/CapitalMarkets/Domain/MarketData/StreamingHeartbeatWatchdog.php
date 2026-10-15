<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DomainException;

/**
 * Monotonic-clock deadline evaluator.
 * Caller must invalidate StreamingRecoveryGate on timeout. Market inactivity
 * and missed protocol heartbeat are tracked independently.
 */
final class StreamingHeartbeatWatchdog
{
    private ?int $lastPongAtMs=null;
    private ?int $lastMessageAtMs=null;

    public function __construct(
        private readonly int $heartbeatTimeoutMs,
        private readonly int $messageTimeoutMs,
    ){
        if($heartbeatTimeoutMs<1||$messageTimeoutMs<1)throw new DomainException('Watchdog timeouts must be positive.');
    }

    public function begin(int $nowMs):void
    {
        $this->assertTime($nowMs);
        $this->lastPongAtMs=$nowMs;
        $this->lastMessageAtMs=$nowMs;
    }

    public function pong(int $nowMs):void
    {
        $this->assertMonotonic($nowMs,$this->lastPongAtMs);
        $this->lastPongAtMs=$nowMs;
    }

    public function message(int $nowMs):void
    {
        $this->assertMonotonic($nowMs,$this->lastMessageAtMs);
        $this->lastMessageAtMs=$nowMs;
    }

    /**
     * $expectMessages=false for an expected idle/closed market session.
     * Protocol heartbeat must still pass.
     */
    public function failureReason(int $nowMs,bool $expectMessages):?string
    {
        $this->assertTime($nowMs);
        if($this->lastPongAtMs===null||$this->lastMessageAtMs===null)return 'NOT_STARTED';
        if($nowMs<$this->lastPongAtMs||$nowMs<$this->lastMessageAtMs)throw new DomainException('Clock moved backwards.');
        if($nowMs-$this->lastPongAtMs>$this->heartbeatTimeoutMs)return 'HEARTBEAT_TIMEOUT';
        if($expectMessages&&$nowMs-$this->lastMessageAtMs>$this->messageTimeoutMs)return 'STREAM_STALE';
        return null;
    }

    private function assertMonotonic(int $nowMs,?int $previous):void
    {
        $this->assertTime($nowMs);
        if($previous===null)throw new DomainException('Watchdog not started.');
        if($nowMs<$previous)throw new DomainException('Clock moved backwards.');
    }

    private function assertTime(int $nowMs):void
    {
        if($nowMs<0)throw new DomainException('Invalid monotonic timestamp.');
    }
}
