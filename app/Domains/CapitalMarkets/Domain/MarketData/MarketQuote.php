<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketQuote extends ValueObject implements MarketObservation
{
    public function __construct(
        public Price $bidPrice,
        public Quantity $bidQuantity,
        public Price $askPrice,
        public Quantity $askQuantity,
        private MarketEventType $type=MarketEventType::Bbo,
    ){
        if(!in_array($this->type,[MarketEventType::Quote,MarketEventType::Bbo],true)){
            throw new InvalidArgumentException('MarketQuote supports only QUOTE or BBO event types.');
        }
        if(!$this->bidPrice->baseAsset->equals($this->askPrice->baseAsset)||!$this->bidPrice->quoteAsset->equals($this->askPrice->quoteAsset)){
            throw new InvalidArgumentException('Bid and ask prices must use the same market pair.');
        }
        if(!$this->bidQuantity->asset->equals($this->bidPrice->baseAsset)||!$this->askQuantity->asset->equals($this->askPrice->baseAsset)){
            throw new InvalidArgumentException('Bid/ask quantities must use the quote base asset.');
        }
    }

    public function eventType():MarketEventType{return $this->type;}

    public function isCrossed():bool
    {
        return $this->bidPrice->value->compareTo($this->askPrice->value)>0;
    }

    public function midPrice():Decimal
    {
        return DecimalMath::midpoint(
            $this->bidPrice->value,
            $this->askPrice->value,
            max($this->bidPrice->precision,$this->askPrice->precision)+1,
        );
    }

    public function spreadAbsolute():Decimal
    {
        return DecimalMath::subtract($this->askPrice->value,$this->bidPrice->value);
    }

    public function spreadBps():Decimal
    {
        return DecimalMath::basisPoints($this->spreadAbsolute(),$this->midPrice(),6);
    }

    public function toArray():array
    {
        return [
            'bid_price'=>$this->bidPrice->toArray(),
            'bid_quantity'=>$this->bidQuantity->toArray(),
            'ask_price'=>$this->askPrice->toArray(),
            'ask_quantity'=>$this->askQuantity->toArray(),
            'mid_price'=>$this->midPrice()->value(),
            'spread_absolute'=>$this->spreadAbsolute()->value(),
            'spread_bps'=>$this->spreadBps()->value(),
        ];
    }
}
