<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use DomainException;
final class ExecutablePriceCalculator
{
    /** @return array{price:Decimal,filled_quantity:Decimal,remaining_quantity:Decimal,notional:Decimal,fully_filled:bool} */
    public function executableFill(MarketOrderBook $book,ExecutionSide $side,Decimal $requestedQuantity):array
    {
        if(!$requestedQuantity->isPositive())throw new DomainException('Requested quantity must be positive.');
        $levels=$side===ExecutionSide::Buy?$book->asks:$book->bids;
        $remaining=$requestedQuantity;$filled=Decimal::fromString('0');$notional=Decimal::fromString('0');
        foreach($levels as $level){
            /** @var OrderBookLevel $level */
            if(!$remaining->isPositive())break;
            $available=$level->quantity->value;
            $take=$available->compareTo($remaining)<=0?$available:$remaining;
            $notional=DecimalMath::add($notional,DecimalMath::multiply($take,$level->price->value));
            $filled=DecimalMath::add($filled,$take);
            $remaining=DecimalMath::subtract($remaining,$take);
        }
        if(!$filled->isPositive())throw new DomainException('ZERO_LIQUIDITY');
        return [
            'price'=>DecimalMath::divide($notional,$filled,12),
            'filled_quantity'=>$filled,
            'remaining_quantity'=>$remaining,
            'notional'=>$notional,
            'fully_filled'=>$remaining->isZero(),
        ];
    }

    /** @return array{price:Decimal,filled_quantity:Decimal,notional:Decimal} */
    public function vwap(MarketOrderBook $book,ExecutionSide $side,Decimal $requestedQuantity):array
    {
        $fill=$this->executableFill($book,$side,$requestedQuantity);
        if(!$fill['fully_filled'])throw new DomainException('INSUFFICIENT_LIQUIDITY');
        return ['price'=>$fill['price'],'filled_quantity'=>$fill['filled_quantity'],'notional'=>$fill['notional']];
    }
}
