<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;

final readonly class MarketTrade implements MarketEventPayload
{
    public function __construct(
        public string $tradeId,
        public Price $price,
        public Quantity $quantity,
        public ?TradeSide $side,
    ){
        if(trim($this->tradeId)==='')throw new InvalidArgumentException('Trade id is required.');
        if(!$this->quantity->asset->equals($this->price->baseAsset)){
            throw new InvalidArgumentException('Trade quantity must use the price base asset.');
        }
    }

    public function toArray():array{return [
        'trade_id'=>$this->tradeId,
        'price'=>$this->price->toArray(),
        'quantity'=>$this->quantity->toArray(),
        'side'=>$this->side?->value,
    ];}
}
