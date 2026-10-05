<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketDataBatch extends ValueObject
{
    /** @param list<RawMarketEvent> $events */
    public function __construct(
        public MarketSourceId $sourceId,
        public array $events,
        public ?string $cursor=null,
    ){
        foreach($this->events as $event){
            if(!$event instanceof RawMarketEvent)throw new InvalidArgumentException('Market data batch events must be typed.');
            if(!$event->sourceId->equals($this->sourceId))throw new InvalidArgumentException('Market data batch contains event from another source.');
        }
        if($this->cursor!==null&&($this->cursor===''||mb_strlen($this->cursor)>1000))throw new InvalidArgumentException('Market data batch cursor is invalid.');
    }
}
