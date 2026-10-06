<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Service;
use Domains\CapitalMarkets\Domain\Opportunity\OpportunityCostEstimate;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final class NetEconomicsEngine
{
    public function estimate(
        Decimal $buyPrice,Decimal $sellPrice,Decimal $quantity,
        Decimal $buyFeeRate,Decimal $sellFeeRate,
        Decimal $buySlippage,Decimal $sellSlippage,
        ?Decimal $fxCost=null,?Decimal $hedgeCost=null,?Decimal $financingCost=null,
        ?Decimal $networkCost=null,?Decimal $settlementCost=null,?Decimal $executionRiskAllowance=null,
    ):OpportunityCostEstimate{
        $zero=Decimal::fromString('0');
        $buyNotional=DecimalMath::multiply($buyPrice,$quantity);
        $sellNotional=DecimalMath::multiply($sellPrice,$quantity);
        $gross=DecimalMath::subtract($sellNotional,$buyNotional);
        return new OpportunityCostEstimate(
            $gross,
            DecimalMath::multiply($buyNotional,$buyFeeRate),
            DecimalMath::multiply($sellNotional,$sellFeeRate),
            $buySlippage,$sellSlippage,
            $fxCost??$zero,$hedgeCost??$zero,$financingCost??$zero,$networkCost??$zero,$settlementCost??$zero,$executionRiskAllowance??$zero,
            $buyNotional,
        );
    }
}
