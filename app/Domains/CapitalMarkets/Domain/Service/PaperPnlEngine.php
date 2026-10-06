<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class PaperPnlEngine
{
    /** @param list<PaperFill> $fills */
    public function realized(array $fills):Decimal
    {
        $cash=Decimal::fromString('0');
        foreach($fills as $fill){
            $notional=$fill->notional();
            $cash=$fill->side===ExecutionSide::Sell?DecimalMath::add($cash,$notional):DecimalMath::subtract($cash,$notional);
            $cash=DecimalMath::subtract($cash,$fill->fee);
            $cash=DecimalMath::subtract($cash,$fill->slippage);
        }
        return $cash;
    }
}
