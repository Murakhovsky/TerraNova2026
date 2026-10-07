<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\SpotPerpetualMarketState;
use Domains\CapitalMarkets\Domain\Opportunity\ExpectedEconomics;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final readonly class RelativeValueEconomicsCalculator
{
    public function __construct(private FundingCashflowCalculator $funding){}

    public function spotPerp(
        SpotPerpetualMarketState $state,
        Decimal $quantity,
        int $holdingHorizonSeconds,
        Decimal $spotFeeRate,
        Decimal $perpFeeRate,
        Decimal $roundTripSlippageBps,
        Decimal $leverage,
        Decimal $targetBasisAbsolute,
        Decimal $riskAllowance,
        Decimal $networkCosts,
        Decimal $emergencyHedgeBuffer,
        bool $includeBasisConvergence,
    ):ExpectedEconomics{
        if(!$quantity->isPositive()||$holdingHorizonSeconds<1||!$leverage->isPositive()){
            throw new InvalidArgumentException('Spot/perpetual economics require positive quantity, horizon and leverage.');
        }
        foreach([$spotFeeRate,$perpFeeRate,$roundTripSlippageBps,$riskAllowance,$networkCosts,$emergencyHedgeBuffer] as $cost){
            if($cost->isNegative())throw new InvalidArgumentException('Spot/perpetual cost inputs cannot be negative.');
        }

        $spotPrice=$state->basis->spotAsk;
        $perpPrice=$state->markPrice??$state->basis->perpBid;
        $spotNotional=DecimalMath::multiply($spotPrice,$quantity);
        $perpNotional=DecimalMath::multiply($perpPrice,$quantity);

        $entryBasis=$state->basis->longSpotShortPerpExecutableBasis;
        $basisMove=DecimalMath::multiply(
            DecimalMath::subtract($entryBasis,$targetBasisAbsolute),
            $quantity
        );
        if(!$includeBasisConvergence)$basisMove=Decimal::fromString('0');

        $fundingPerSettlement=$this->funding->calculate($state->funding,$perpNotional,PositionSide::Short);
        $settlements=$this->settlementCount($state->funding,$state->updatedAt,$holdingHorizonSeconds);
        $fundingPnl=DecimalMath::multiplyInteger($fundingPerSettlement,$settlements);

        $fees=DecimalMath::multiplyInteger(
            DecimalMath::add(
                DecimalMath::multiply($spotNotional,$spotFeeRate),
                DecimalMath::multiply($perpNotional,$perpFeeRate)
            ),
            2
        );
        $slippageBase=DecimalMath::add($spotNotional,$perpNotional);
        $slippage=DecimalMath::multiplyInteger(
            DecimalMath::divide(
                DecimalMath::multiply($slippageBase,$roundTripSlippageBps),
                Decimal::fromString('10000'),18
            ),
            2
        );
        $perpMargin=DecimalMath::divide($perpNotional,$leverage,18);
        $capital=DecimalMath::add(DecimalMath::add($spotNotional,$perpMargin),$emergencyHedgeBuffer);

        return new ExpectedEconomics(
            $basisMove,
            $fundingPnl,
            $basisMove,
            $fees,
            $slippage,
            Decimal::fromString('0'),
            $networkCosts,
            $riskAllowance,
            $capital,
            $holdingHorizonSeconds,
        );
    }

    public function historicalSpotPerp(
        \Domains\CapitalMarkets\Domain\MarketData\BasisObservation $basis,
        FundingRateObservation $funding,
        Decimal $quantity,
        int $holdingHorizonSeconds,
        Decimal $spotFeeRate,
        Decimal $perpFeeRate,
        Decimal $roundTripSlippageBps,
        Decimal $leverage,
        Decimal $targetBasisAbsolute,
        Decimal $riskAllowance,
        Decimal $networkCosts,
        Decimal $emergencyHedgeBuffer,
        bool $includeBasisConvergence,
    ):ExpectedEconomics{
        if(!$quantity->isPositive()||$holdingHorizonSeconds<1||!$leverage->isPositive()){
            throw new InvalidArgumentException('Historical spot/perpetual economics require positive quantity, horizon and leverage.');
        }
        foreach([$spotFeeRate,$perpFeeRate,$roundTripSlippageBps,$riskAllowance,$networkCosts,$emergencyHedgeBuffer] as $cost){
            if($cost->isNegative())throw new InvalidArgumentException('Historical spot/perpetual cost inputs cannot be negative.');
        }

        $spotNotional=DecimalMath::multiply($basis->spotAsk,$quantity);
        $perpPrice=$basis->markPrice??$basis->perpBid;
        $perpNotional=DecimalMath::multiply($perpPrice,$quantity);
        $basisMove=$includeBasisConvergence
            ? DecimalMath::multiply(
                DecimalMath::subtract($basis->longSpotShortPerpExecutableBasis,$targetBasisAbsolute),
                $quantity
            )
            : Decimal::fromString('0');

        $fundingPerSettlement=$this->funding->calculate($funding,$perpNotional,PositionSide::Short);
        $settlements=$this->settlementCount($funding,$basis->timestamp,$holdingHorizonSeconds);
        $fundingPnl=DecimalMath::multiplyInteger($fundingPerSettlement,$settlements);

        $fees=DecimalMath::multiplyInteger(
            DecimalMath::add(
                DecimalMath::multiply($spotNotional,$spotFeeRate),
                DecimalMath::multiply($perpNotional,$perpFeeRate)
            ),2
        );
        $slippage=DecimalMath::multiplyInteger(
            DecimalMath::divide(
                DecimalMath::multiply(DecimalMath::add($spotNotional,$perpNotional),$roundTripSlippageBps),
                Decimal::fromString('10000'),18
            ),2
        );
        $capital=DecimalMath::add(
            DecimalMath::add($spotNotional,DecimalMath::divide($perpNotional,$leverage,18)),
            $emergencyHedgeBuffer
        );

        return new ExpectedEconomics(
            $basisMove,$fundingPnl,$basisMove,$fees,$slippage,Decimal::fromString('0'),
            $networkCosts,$riskAllowance,$capital,$holdingHorizonSeconds
        );
    }

    public function crossVenueFunding(
        FundingRateObservation $longFunding,
        FundingRateObservation $shortFunding,
        Decimal $longMarkPrice,
        Decimal $shortMarkPrice,
        Decimal $quantity,
        int $holdingHorizonSeconds,
        Decimal $longFeeRate,
        Decimal $shortFeeRate,
        Decimal $roundTripSlippageBps,
        Decimal $longLeverage,
        Decimal $shortLeverage,
        Decimal $riskAllowance,
        Decimal $networkCosts,
        Decimal $emergencyHedgeBuffer,
        DateTimeImmutable $asOf,
    ):ExpectedEconomics{
        if(!$quantity->isPositive()||$holdingHorizonSeconds<1||!$longLeverage->isPositive()||!$shortLeverage->isPositive()){
            throw new InvalidArgumentException('Cross-venue economics require positive quantity, horizon and leverage.');
        }
        foreach([$longFeeRate,$shortFeeRate,$roundTripSlippageBps,$riskAllowance,$networkCosts,$emergencyHedgeBuffer] as $cost){
            if($cost->isNegative())throw new InvalidArgumentException('Cross-venue cost inputs cannot be negative.');
        }

        $longNotional=DecimalMath::multiply($longMarkPrice,$quantity);
        $shortNotional=DecimalMath::multiply($shortMarkPrice,$quantity);
        $longCashflow=DecimalMath::multiplyInteger(
            $this->funding->calculate($longFunding,$longNotional,PositionSide::Long),
            $this->settlementCount($longFunding,$asOf,$holdingHorizonSeconds)
        );
        $shortCashflow=DecimalMath::multiplyInteger(
            $this->funding->calculate($shortFunding,$shortNotional,PositionSide::Short),
            $this->settlementCount($shortFunding,$asOf,$holdingHorizonSeconds)
        );
        $fundingPnl=DecimalMath::add($longCashflow,$shortCashflow);

        $fees=DecimalMath::multiplyInteger(
            DecimalMath::add(
                DecimalMath::multiply($longNotional,$longFeeRate),
                DecimalMath::multiply($shortNotional,$shortFeeRate)
            ),
            2
        );
        $slippage=DecimalMath::multiplyInteger(
            DecimalMath::divide(
                DecimalMath::multiply(DecimalMath::add($longNotional,$shortNotional),$roundTripSlippageBps),
                Decimal::fromString('10000'),18
            ),
            2
        );
        $capital=DecimalMath::add(
            DecimalMath::add(
                DecimalMath::divide($longNotional,$longLeverage,18),
                DecimalMath::divide($shortNotional,$shortLeverage,18)
            ),
            $emergencyHedgeBuffer
        );

        return new ExpectedEconomics(
            Decimal::fromString('0'),
            $fundingPnl,
            Decimal::fromString('0'),
            $fees,
            $slippage,
            Decimal::fromString('0'),
            $networkCosts,
            $riskAllowance,
            $capital,
            $holdingHorizonSeconds,
        );
    }

    public function settlementCount(FundingRateObservation $funding,DateTimeImmutable $asOf,int $horizonSeconds):int
    {
        if($horizonSeconds<1||$funding->nextSettlementAt===null)return 0;
        $end=$asOf->modify('+'.$horizonSeconds.' seconds');
        if($funding->nextSettlementAt>$end)return 0;
        $remaining=max(0,$end->getTimestamp()-$funding->nextSettlementAt->getTimestamp());
        return 1+intdiv($remaining,$funding->fundingIntervalSeconds);
    }
}
