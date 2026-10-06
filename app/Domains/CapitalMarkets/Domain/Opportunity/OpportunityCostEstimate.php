<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Kernel\Shared\Domain\ValueObject;
final readonly class OpportunityCostEstimate extends ValueObject
{
    public Decimal $totalCost;
    public Decimal $expectedNetPnl;
    public Decimal $expectedNetEdgeBps;
    public function __construct(
        public Decimal $grossPnl, public Decimal $buyFee, public Decimal $sellFee,
        public Decimal $buySlippage, public Decimal $sellSlippage, public Decimal $fxCost,
        public Decimal $hedgeCost, public Decimal $financingCost, public Decimal $networkCost,
        public Decimal $settlementCost, public Decimal $executionRiskAllowance, public Decimal $requiredCapital,
    ){
        $cost=Decimal::fromString('0');
        foreach([$buyFee,$sellFee,$buySlippage,$sellSlippage,$fxCost,$hedgeCost,$financingCost,$networkCost,$settlementCost,$executionRiskAllowance] as $part){
            $cost=DecimalMath::add($cost,$part);
        }
        $this->totalCost=$cost;
        $this->expectedNetPnl=DecimalMath::subtract($grossPnl,$cost);
        $this->expectedNetEdgeBps=$requiredCapital->isZero()?Decimal::fromString('0'):DecimalMath::basisPoints($this->expectedNetPnl,$requiredCapital,6);
    }
}
