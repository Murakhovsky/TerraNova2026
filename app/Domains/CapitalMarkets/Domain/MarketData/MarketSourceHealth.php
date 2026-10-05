<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketSourceHealth extends ValueObject
{
    public function __construct(
        public MarketSourceId $sourceId,
        public MarketConnectionState $connectionState,
        public ?DateTimeImmutable $lastEventAt,
        public ?DateTimeImmutable $lastHeartbeatAt,
        public int $failureCount,
        public int $queueLag,
        public bool $clockReliable,
        public ?string $lastError=null,
        public ?Decimal $messagesPerSecond=null,
        public ?int $lastLatencyMilliseconds=null,
        public int $errorCount=0,
        public int $reconnectCount=0,
        public MarketRateLimitState $rateLimitState=MarketRateLimitState::Unknown,
    ){
        if($this->failureCount<0||$this->queueLag<0||$this->errorCount<0||$this->reconnectCount<0)throw new InvalidArgumentException('Source health counters cannot be negative.');
        if($this->messagesPerSecond?->isNegative())throw new InvalidArgumentException('Messages per second cannot be negative.');
        if($this->lastLatencyMilliseconds!==null&&$this->lastLatencyMilliseconds<0)throw new InvalidArgumentException('Source latency cannot be negative.');
        if($this->lastError!==null&&mb_strlen($this->lastError)>1000)throw new InvalidArgumentException('Source health error is too long.');
    }

    public function liveTrustAvailable():bool
    {
        return $this->connectionState->canTrustLiveData()&&$this->clockReliable;
    }
}
