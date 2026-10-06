<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Execution\ExecutionSide;
use Domains\CapitalMarkets\Domain\Execution\PaperFill;
use Domains\CapitalMarkets\Domain\Portfolio\Position;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use DomainException;

final class PositionProjector
{
    /** @param list<PaperFill> $fills */
    public function project(
        string $portfolioId,
        string $strategyId,
        string $instrumentId,
        string $venueId,
        array $fills,
        Decimal $markPrice,
    ):Position{
        $quantity=Decimal::fromString('0');
        $costBasis=Decimal::fromString('0');
        $realized=Decimal::fromString('0');
        $fees=Decimal::fromString('0');
        $openedAt=null;
        $updatedAt=null;

        foreach($fills as $fill){
            if(!$fill instanceof PaperFill)throw new DomainException('POSITION_PROJECTOR_REQUIRES_TYPED_FILLS');
            if($fill->instrumentId!==$instrumentId||$fill->venueId!==$venueId)continue;

            $openedAt??=$fill->filledAt;
            $updatedAt=$fill->filledAt;
            $fees=DecimalMath::add($fees,$fill->fee);

            if($fill->side===ExecutionSide::Buy){
                $quantity=DecimalMath::add($quantity,$fill->quantity);
                $costBasis=DecimalMath::add($costBasis,$fill->notional());
                continue;
            }

            if($fill->quantity->compareTo($quantity)>0){
                throw new DomainException('POSITION_SELL_EXCEEDS_AVAILABLE_QUANTITY');
            }

            $average=$quantity->isZero()?Decimal::fromString('0'):DecimalMath::divide($costBasis,$quantity,12);
            $releasedCost=DecimalMath::multiply($average,$fill->quantity);
            $realized=DecimalMath::add($realized,DecimalMath::subtract($fill->notional(),$releasedCost));
            $quantity=DecimalMath::subtract($quantity,$fill->quantity);
            $costBasis=DecimalMath::subtract($costBasis,$releasedCost);
        }

        $average=$quantity->isZero()?Decimal::fromString('0'):DecimalMath::divide($costBasis,$quantity,12);
        $openedAt??=new DateTimeImmutable('@0');
        $updatedAt??=$openedAt;

        return new Position(
            $instrumentId,$venueId,$quantity,$average,$markPrice,$fees,$realized,
            'cm_pos_'.substr(hash('sha256',$portfolioId.'|'.$strategyId.'|'.$venueId.'|'.$instrumentId),0,40),
            $portfolioId,$strategyId,$openedAt,$updatedAt,$quantity->isZero()?$updatedAt:null
        );
    }
}
