<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use InvalidArgumentException;

final readonly class OrderBookLevel
{
    public function __construct(
        public Price $price,
        public Quantity $quantity,
        public ?int $orderCount=null,
    ){
        if(!$this->quantity->asset->equals($this->price->baseAsset)){
            throw new InvalidArgumentException('Order-book quantity must use the price base asset.');
        }
        if($this->orderCount!==null&&$this->orderCount<0)throw new InvalidArgumentException('Order count cannot be negative.');
    }

    public function toArray():array{return [
        'price'=>$this->price->toArray(),
        'quantity'=>$this->quantity->toArray(),
        'order_count'=>$this->orderCount,
    ];}
}
