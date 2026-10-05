<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketCandle extends ValueObject implements MarketObservation
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public DateTimeImmutable $closeTime,
        public Price $open,
        public Price $high,
        public Price $low,
        public Price $close,
        public Decimal $volume,
        public string $sourceType,
    ){
        if($this->closeTime<=$this->openTime)throw new InvalidArgumentException('Candle close time must be later than open time.');
        foreach([$this->high,$this->low,$this->close] as $price){
            if(!$price->baseAsset->equals($this->open->baseAsset)||!$price->quoteAsset->equals($this->open->quoteAsset)){
                throw new InvalidArgumentException('Candle prices must use the same market pair.');
            }
        }
        if($this->volume->isNegative())throw new InvalidArgumentException('Candle volume cannot be negative.');
        if($this->sourceType===''||trim($this->sourceType)!==$this->sourceType)throw new InvalidArgumentException('Candle source type is required.');
    }

    public function eventType():MarketEventType{return MarketEventType::Candle;}

    public function toArray():array
    {
        return [
            'open_time'=>$this->openTime->format(DATE_ATOM),
            'close_time'=>$this->closeTime->format(DATE_ATOM),
            'open'=>$this->open->toArray(),
            'high'=>$this->high->toArray(),
            'low'=>$this->low->toArray(),
            'close'=>$this->close->toArray(),
            'volume'=>$this->volume->value(),
            'source_type'=>$this->sourceType,
        ];
    }
}
