<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;

final readonly class ReconnectPolicy
{
    public function __construct(
        public int $initialDelaySeconds,
        public int $maxDelaySeconds,
        public int $multiplier=2,
        public int $heartbeatIntervalSeconds=20,
        public int $heartbeatTimeoutSeconds=10,
    ){
        if($this->initialDelaySeconds<1||$this->maxDelaySeconds<$this->initialDelaySeconds||$this->multiplier<1){
            throw new InvalidArgumentException('Reconnect policy is invalid.');
        }
        if($this->heartbeatIntervalSeconds<1||$this->heartbeatTimeoutSeconds<1){
            throw new InvalidArgumentException('Heartbeat policy is invalid.');
        }
    }

    public function delayForAttempt(int $attempt):int
    {
        if($attempt<1)throw new InvalidArgumentException('Reconnect attempt must be positive.');
        $delay=$this->initialDelaySeconds;
        for($i=1;$i<$attempt&&$delay<$this->maxDelaySeconds;$i++){
            $delay=min($this->maxDelaySeconds,$delay*$this->multiplier);
        }
        return $delay;
    }

    /** @return array<string,int> */
    public function toArray():array{return [
        'initial_delay_seconds'=>$this->initialDelaySeconds,
        'max_delay_seconds'=>$this->maxDelaySeconds,
        'multiplier'=>$this->multiplier,
        'heartbeat_interval_seconds'=>$this->heartbeatIntervalSeconds,
        'heartbeat_timeout_seconds'=>$this->heartbeatTimeoutSeconds,
    ];}
}
