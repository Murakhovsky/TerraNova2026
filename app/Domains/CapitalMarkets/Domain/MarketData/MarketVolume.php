<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;

final readonly class MarketVolume implements MarketEventPayload
{
    public function __construct(public Quantity $volume,public int $windowSeconds)
    {
        if($this->windowSeconds<1)throw new InvalidArgumentException('Volume window must be positive.');
    }

    public function toArray():array{return ['volume'=>$this->volume->toArray(),'window_seconds'=>$this->windowSeconds];}
}
