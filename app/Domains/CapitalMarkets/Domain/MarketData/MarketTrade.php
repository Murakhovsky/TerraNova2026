<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketTrade extends ValueObject implements MarketObservation
{
    public function __construct(
        public string $tradeId,
        public Price $price,
        public Quantity $quantity,
        public ?TradeSide $side,
    ){
        if($this->tradeId===''||trim($this->tradeId)!==$this->tradeId||mb_strlen($this->tradeId)>190){
            throw new InvalidArgumentException('Market trade id is invalid.');
        }
        if(!$this->quantity->asset->equals($this->price->baseAsset)){
            throw new InvalidArgumentException('Trade quantity must use the traded base asset.');
        }
    }

    public function eventType():MarketEventType{return MarketEventType::Trade;}

    public function toArray():array
    {
        return [
            'trade_id'=>$this->tradeId,
            'price'=>$this->price->toArray(),
            'quantity'=>$this->quantity->toArray(),
            'side'=>$this->side?->value,
        ];
    }
}
