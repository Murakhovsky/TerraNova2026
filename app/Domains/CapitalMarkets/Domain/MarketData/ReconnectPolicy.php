<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ReconnectPolicy extends ValueObject
{
    public function __construct(
        public int $initialDelayMilliseconds=1000,
        public int $maximumDelayMilliseconds=30000,
        public int $maximumAttempts=0,
    ){
        if($this->initialDelayMilliseconds<1||$this->maximumDelayMilliseconds<$this->initialDelayMilliseconds){
            throw new InvalidArgumentException('Reconnect delays are invalid.');
        }
        if($this->maximumAttempts<0)throw new InvalidArgumentException('Reconnect maximum attempts cannot be negative.');
    }

    public function delayForAttempt(int $attempt):int
    {
        if($attempt<1)throw new InvalidArgumentException('Reconnect attempt must be positive.');
        $delay=$this->initialDelayMilliseconds;
        for($i=1;$i<$attempt&&$delay<$this->maximumDelayMilliseconds;$i++){
            $delay=min($this->maximumDelayMilliseconds,$delay*2);
        }
        return $delay;
    }

    public function allowsAttempt(int $attempt):bool
    {
        return $this->maximumAttempts===0||$attempt<=$this->maximumAttempts;
    }
}
