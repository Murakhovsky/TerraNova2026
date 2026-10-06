<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\MarketData\SpotPerpetualMarketState;
use Domains\CapitalMarkets\Domain\Portfolio\HedgeGroup;
use Domains\CapitalMarkets\Domain\Risk\DerivativesRiskPolicy;
use Domains\CapitalMarkets\Domain\Risk\LiquidationRiskState;
use Domains\CapitalMarkets\Domain\Risk\LiquidationRiskStatus;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class RelativeValueRiskEvaluator
{
    /** @return array{accepted:bool,blocking_reasons:list<string>,warnings:list<string>,net_delta:string} */
    public function assess(
        SpotPerpetualMarketState $market,
        HedgeGroup $hedge,
        DerivativesRiskPolicy $policy,
        LiquidationRiskState $liquidation,
        Decimal $leverage,
        int $unhedgedTimeMs=0,
    ):array{
        $result=$this->assessHedge($market->trusted(),$hedge,$policy,[$liquidation],$leverage,$unhedgedTimeMs);
        if($market->basis->midBasisBps->isNegative())$result['warnings'][]='PERPETUAL_TRADES_AT_DISCOUNT';
        return $result;
    }

    /**
     * @param list<LiquidationRiskState> $liquidations
     * @return array{accepted:bool,blocking_reasons:list<string>,warnings:list<string>,net_delta:string}
     */
    public function assessHedge(
        bool $marketTrusted,
        HedgeGroup $hedge,
        DerivativesRiskPolicy $policy,
        array $liquidations,
        Decimal $leverage,
        int $unhedgedTimeMs=0,
    ):array{
        $blocks=[];$warnings=[];
        if(!$marketTrusted)$blocks[]='UNTRUSTED_MARKET_STATE';
        if($leverage->compareTo($policy->maximumLeverage)>0)$blocks[]='LEVERAGE_LIMIT_EXCEEDED';
        $delta=$hedge->netUnderlyingExposure();
        if(DecimalMath::abs($delta)->compareTo($policy->maximumNetDelta)>0)$blocks[]='HEDGE_DRIFT_RISK';
        if($unhedgedTimeMs>$policy->maximumUnhedgedTimeMs)$blocks[]='HEDGE_BREACH';
        if($liquidations===[])$blocks[]='LIQUIDATION_MODEL_UNAVAILABLE';

        foreach($liquidations as $liquidation){
            if(!$liquidation instanceof LiquidationRiskState)throw new InvalidArgumentException('Liquidation states must be typed.');
            if($liquidation->status===LiquidationRiskStatus::Unknown)$blocks[]='LIQUIDATION_MODEL_UNAVAILABLE';
            if($liquidation->status===LiquidationRiskStatus::Breach)$blocks[]='LIQUIDATION_RISK';
            if($liquidation->status===LiquidationRiskStatus::Warning)$warnings[]='LIQUIDATION_DISTANCE_WARNING';
            if($liquidation->distanceToLiquidation!==null
                && $liquidation->distanceToLiquidation->compareTo($policy->minimumLiquidationDistance)<0){
                $blocks[]='MINIMUM_LIQUIDATION_DISTANCE_BREACHED';
            }
        }

        return [
            'accepted'=>$blocks===[],
            'blocking_reasons'=>array_values(array_unique($blocks)),
            'warnings'=>array_values(array_unique($warnings)),
            'net_delta'=>$delta->value(),
        ];
    }
}
