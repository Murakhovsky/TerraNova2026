<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class RateLimitPolicy extends ValueObject
{
    public function __construct(
        public int $requestBudget,
        public int $windowSeconds,
        public int $initialBackoffMilliseconds=250,
        public int $maximumBackoffMilliseconds=10000,
    ){
        if($this->requestBudget<1)throw new InvalidArgumentException('Rate-limit request budget must be positive.');
        if($this->windowSeconds<1)throw new InvalidArgumentException('Rate-limit window must be positive.');
        if($this->initialBackoffMilliseconds<1||$this->maximumBackoffMilliseconds<$this->initialBackoffMilliseconds){
            throw new InvalidArgumentException('Rate-limit backoff policy is invalid.');
        }
    }
}
