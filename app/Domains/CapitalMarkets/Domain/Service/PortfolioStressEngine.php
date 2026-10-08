<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Stress\PortfolioStressResult;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressScenario;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class PortfolioStressEngine
{
    /** @param list<array<string,mixed>> $positions */
    public function run(PortfolioStressScenario $scenario,Decimal $capital,array $positions):PortfolioStressResult
    {
        $portfolioPnl=Decimal::fromString('0');
        $marginImpact=Decimal::fromString('0');
        $affected=[];
        $venues=[];
        $breaches=[];

        foreach($positions as $position){
            $notional=DecimalMath::abs(Decimal::fromString((string)($position['notional']??'0')));
            $asset=(string)($position['asset']??'');
            $venue=(string)($position['venue']??'');
            $side=strtoupper((string)($position['side']??'LONG'));
            $assetShock=Decimal::fromString((string)($scenario->shocks[$asset]??'0'));
            if(isset($scenario->shocks['ALL'])){
                $assetShock=DecimalMath::add($assetShock,Decimal::fromString((string)$scenario->shocks['ALL']));
            }

            if(!$assetShock->isZero()){
                $pnl=DecimalMath::multiply($notional,$assetShock);
                if($side==='SHORT')$pnl=DecimalMath::negate($pnl);
                $portfolioPnl=DecimalMath::add($portfolioPnl,$pnl);
                $affected[]=(string)($position['position_id']??'');
            }

            if($venue!==''&&(($scenario->shocks['VENUE:'.$venue]??null)==='OFFLINE')){
                $venues[$venue]=true;
                $initialMargin=Decimal::fromString((string)($position['initial_margin']??'0'));
                $availableMargin=Decimal::fromString((string)($position['available_margin']??'0'));
                $marginImpact=DecimalMath::add($marginImpact,DecimalMath::add($initialMargin,$availableMargin));
                $affected[]=(string)($position['position_id']??'');
                $breaches[]='VENUE_OPERATIONAL_UNAVAILABLE:'.$venue;
            }

            $collateralAsset=(string)($position['collateral_asset']??'');
            if($collateralAsset!==''&&isset($scenario->shocks[$collateralAsset])){
                $collateralShock=Decimal::fromString((string)$scenario->shocks[$collateralAsset]);
                if($collateralShock->isNegative()){
                    $collateralValue=DecimalMath::abs(Decimal::fromString((string)($position['collateral_value']??'0')));
                    $collateralLoss=DecimalMath::multiply($collateralValue,DecimalMath::abs($collateralShock));
                    $portfolioPnl=DecimalMath::subtract($portfolioPnl,$collateralLoss);
                    $marginImpact=DecimalMath::add($marginImpact,$collateralLoss);
                    $affected[]=(string)($position['position_id']??'');
                }
            }
        }

        $loss=$portfolioPnl->isNegative()?DecimalMath::abs($portfolioPnl):Decimal::fromString('0');
        $remaining=DecimalMath::subtract($capital,$loss);
        if($remaining->isNegative())$remaining=Decimal::fromString('0');

        return new PortfolioStressResult(
            $scenario->id,
            $loss,
            $marginImpact,
            array_values(array_unique(array_filter($affected,static fn(string $id):bool=>$id!==''))),
            array_keys($venues),
            array_values(array_unique($breaches)),
            $remaining,
        );
    }
}
