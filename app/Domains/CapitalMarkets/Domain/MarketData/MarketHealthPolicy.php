<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketHealthPolicy extends ValueObject
{
    public function __construct(
        public int $heartbeatIntervalMilliseconds=15000,
        public int $heartbeatTimeoutMilliseconds=45000,
        public int $maximumQueueLag=10000,
        public int $maximumClockDriftMilliseconds=1000,
    ){
        if($this->heartbeatIntervalMilliseconds<1||$this->heartbeatTimeoutMilliseconds<$this->heartbeatIntervalMilliseconds){
            throw new InvalidArgumentException('Heartbeat policy is invalid.');
        }
        if($this->maximumQueueLag<0||$this->maximumClockDriftMilliseconds<0){
            throw new InvalidArgumentException('Health thresholds cannot be negative.');
        }
    }
}
