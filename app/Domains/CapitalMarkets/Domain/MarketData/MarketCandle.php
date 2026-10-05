<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;

final readonly class MarketCandle implements MarketEventPayload
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public Price $open,
        public Price $high,
        public Price $low,
        public Price $close,
        public Quantity $volume,
        public string $sourceType,
    ){
        if($this->end<=$this->start)throw new InvalidArgumentException('Candle end must be after start.');
        if(trim($this->sourceType)==='')throw new InvalidArgumentException('Candle source type is required.');
        if($this->high->value->compareTo($this->low->value)<0)throw new InvalidArgumentException('Candle high cannot be below low.');
    }

    public function toArray():array{return [
        'start'=>$this->start->format(DATE_ATOM),'end'=>$this->end->format(DATE_ATOM),
        'open'=>$this->open->toArray(),'high'=>$this->high->toArray(),'low'=>$this->low->toArray(),'close'=>$this->close->toArray(),
        'volume'=>$this->volume->toArray(),'source_type'=>$this->sourceType,
    ];}
}
