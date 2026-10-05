<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
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
    ){
        if($this->failureCount<0||$this->queueLag<0)throw new InvalidArgumentException('Source health counters cannot be negative.');
        if($this->lastError!==null&&mb_strlen($this->lastError)>1000)throw new InvalidArgumentException('Source health error is too long.');
    }

    public function liveTrustAvailable():bool
    {
        return $this->connectionState->canTrustLiveData()&&$this->clockReliable;
    }
}
