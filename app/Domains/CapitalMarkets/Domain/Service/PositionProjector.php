<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use DomainException;

final class PositionProjector
{
    /** @param list<PaperFill> $fills */
    public function project(
        string $portfolioId,string $strategyId,string $instrumentId,string $venueId,array $fills,Decimal $markPrice,
    ):Position{
        return $this->projectDirectional(
            $portfolioId,$strategyId,$instrumentId,$venueId,$fills,$markPrice,PositionSide::Long,Decimal::fromString('1')
        );
    }

    /** @param list<PaperFill> $fills */
    public function projectDirectional(
        string $portfolioId,
        string $strategyId,
        string $instrumentId,
        string $venueId,
        array $fills,
        Decimal $markPrice,
        PositionSide $positionSide,
        Decimal $contractMultiplier,
    ):Position{
        if(!$contractMultiplier->isPositive())throw new DomainException('POSITION_CONTRACT_MULTIPLIER_INVALID');
        $quantity=Decimal::fromString('0');
        $costBasis=Decimal::fromString('0');
        $realized=Decimal::fromString('0');
        $fees=Decimal::fromString('0');
        $openedAt=null;$updatedAt=null;
        $openSide=$positionSide===PositionSide::Long?ExecutionSide::Buy:ExecutionSide::Sell;
        $closeSide=$openSide===ExecutionSide::Buy?ExecutionSide::Sell:ExecutionSide::Buy;

        foreach($fills as $fill){
            if(!$fill instanceof PaperFill)throw new DomainException('POSITION_PROJECTOR_REQUIRES_TYPED_FILLS');
            if($fill->instrumentId!==$instrumentId||$fill->venueId!==$venueId)continue;

            $openedAt??=$fill->filledAt;
            $updatedAt=$fill->filledAt;
            $fees=DecimalMath::add($fees,$fill->fee);

            if($fill->side===$openSide){
                $quantity=DecimalMath::add($quantity,$fill->quantity);
                $costBasis=DecimalMath::add($costBasis,$fill->notional());
                continue;
            }
            if($fill->side!==$closeSide)throw new DomainException('POSITION_FILL_SIDE_INVALID');
            if($fill->quantity->compareTo($quantity)>0){
                throw new DomainException($positionSide===PositionSide::Long
                    ?'POSITION_SELL_EXCEEDS_AVAILABLE_QUANTITY'
                    :'POSITION_BUY_EXCEEDS_SHORT_QUANTITY');
            }

            $average=$quantity->isZero()?Decimal::fromString('0'):DecimalMath::divide($costBasis,$quantity,12);
            $entryNotional=DecimalMath::multiply($average,$fill->quantity);
            $tradePnl=$positionSide===PositionSide::Long
                ?DecimalMath::subtract($fill->notional(),$entryNotional)
                :DecimalMath::subtract($entryNotional,$fill->notional());
            $realized=DecimalMath::add($realized,$tradePnl);
            $quantity=DecimalMath::subtract($quantity,$fill->quantity);
            $costBasis=DecimalMath::subtract($costBasis,$entryNotional);
        }

        $average=$quantity->isZero()?Decimal::fromString('0'):DecimalMath::divide($costBasis,$quantity,12);
        $openedAt??=new DateTimeImmutable('@0');$updatedAt??=$openedAt;

        return new Position(
            $instrumentId,$venueId,$quantity,$average,$markPrice,$fees,$realized,
            'cm_pos_'.substr(hash('sha256',$portfolioId.'|'.$strategyId.'|'.$venueId.'|'.$instrumentId.'|'.$positionSide->value),0,40),
            $portfolioId,$strategyId,$openedAt,$updatedAt,$quantity->isZero()?$updatedAt:null,$positionSide,$contractMultiplier
        );
    }
}
