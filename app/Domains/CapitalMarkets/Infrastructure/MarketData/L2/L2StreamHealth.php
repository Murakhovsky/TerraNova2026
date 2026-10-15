<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Tracks ingress activity without conflating heartbeats and market updates. */
final class L2StreamHealth
{
    private ?int $lastFrameAtMs = null;
    private ?int $lastDataAtMs = null;
    private int $dataFrames = 0;
    private int $controlFrames = 0;
    private int $failures = 0;

    public function record(int $timestampMs, string $kind): void
    {
        if ($timestampMs < 0 || !in_array($kind, ['DATA','CONTROL','FAILURE'], true)) throw new DomainException('Invalid stream health event.');
        if ($this->lastFrameAtMs !== null && $timestampMs < $this->lastFrameAtMs) throw new DomainException('Non-monotonic stream clock.');
        $this->lastFrameAtMs = $timestampMs;
        if ($kind === 'DATA') { $this->lastDataAtMs = $timestampMs; ++$this->dataFrames; }
        if ($kind === 'CONTROL') ++$this->controlFrames;
        if ($kind === 'FAILURE') ++$this->failures;
    }

    public function isStale(int $nowMs, int $maxSilenceMs): bool
    {
        if ($nowMs < 0 || $maxSilenceMs < 1) throw new DomainException('Invalid staleness threshold.');
        return $this->lastDataAtMs === null || $nowMs < $this->lastDataAtMs || $nowMs - $this->lastDataAtMs > $maxSilenceMs;
    }

    public function diagnostics(): array
    {
        return ['last_frame_ms'=>$this->lastFrameAtMs,'last_data_ms'=>$this->lastDataAtMs,'data_frames'=>$this->dataFrames,'control_frames'=>$this->controlFrames,'failures'=>$this->failures];
    }
}
