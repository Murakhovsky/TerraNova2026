<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;

final readonly class MarketQuote implements MarketEventPayload
{
    public function __construct(
        public Price $bidPrice,
        public Quantity $bidQuantity,
        public Price $askPrice,
        public Quantity $askQuantity,
    ){
        if(!$this->bidPrice->baseAsset->equals($this->askPrice->baseAsset)
            ||!$this->bidPrice->quoteAsset->equals($this->askPrice->quoteAsset)){
            throw new InvalidArgumentException('Quote bid and ask must use the same market pair.');
        }
        if(!$this->bidQuantity->asset->equals($this->bidPrice->baseAsset)
            ||!$this->askQuantity->asset->equals($this->askPrice->baseAsset)){
            throw new InvalidArgumentException('Quote quantities must use the base asset.');
        }
    }

    public function isCrossed():bool{return $this->bidPrice->value->compareTo($this->askPrice->value)>0;}

    public function mid():Decimal
    {
        return DecimalMath::divide(
            DecimalMath::add($this->bidPrice->value,$this->askPrice->value),
            Decimal::fromString('2'),
            min(30,max($this->bidPrice->precision,$this->askPrice->precision)+1),
        );
    }

    public function spreadAbsolute():Decimal
    {
        return DecimalMath::subtract($this->askPrice->value,$this->bidPrice->value);
    }

    public function spreadBps():Decimal
    {
        $mid=$this->mid();
        if($mid->isZero())return Decimal::fromString('0');
        return DecimalMath::divide(DecimalMath::multiplyByInt($this->spreadAbsolute(),10_000),$mid,8);
    }

    public function toArray():array{return [
        'bid_price'=>$this->bidPrice->toArray(),
        'bid_quantity'=>$this->bidQuantity->toArray(),
        'ask_price'=>$this->askPrice->toArray(),
        'ask_quantity'=>$this->askQuantity->toArray(),
        'mid'=>$this->mid()->value(),
        'spread_absolute'=>$this->spreadAbsolute()->value(),
        'spread_bps'=>$this->spreadBps()->value(),
    ];}
}
