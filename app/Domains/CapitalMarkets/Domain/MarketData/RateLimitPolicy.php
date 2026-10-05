<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;

final readonly class RateLimitPolicy
{
    public function __construct(
        public int $requestBudget,
        public int $windowSeconds,
        public int $maxBackoffSeconds,
    ){
        if($this->requestBudget<1||$this->windowSeconds<1||$this->maxBackoffSeconds<1){
            throw new InvalidArgumentException('Rate-limit policy values must be positive.');
        }
    }

    /** @return array<string,int> */
    public function toArray():array{return [
        'request_budget'=>$this->requestBudget,
        'window_seconds'=>$this->windowSeconds,
        'max_backoff_seconds'=>$this->maxBackoffSeconds,
    ];}
}
