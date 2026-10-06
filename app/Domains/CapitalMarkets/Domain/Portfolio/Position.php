<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Portfolio;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Position extends ValueObject
{
    public function __construct(
        public string $instrumentId,
        public string $venueId,
        public Decimal $quantity,
        public Decimal $averageEntryPrice,
        public Decimal $markPrice,
        public Decimal $fees,
        public Decimal $realizedPnl,
        public ?string $positionId=null,
        public ?string $portfolioId=null,
        public ?string $strategyId=null,
        public ?DateTimeImmutable $openedAt=null,
        public ?DateTimeImmutable $updatedAt=null,
        public ?DateTimeImmutable $closedAt=null,
        public PositionSide $side=PositionSide::Long,
        public ?Decimal $contractMultiplier=null,
    ){
        if($instrumentId===''||$venueId===''||$quantity->isNegative()){
            throw new InvalidArgumentException('Invalid position identity or quantity.');
        }
        if($contractMultiplier!==null&&!$contractMultiplier->isPositive()){
            throw new InvalidArgumentException('Position contract multiplier must be positive.');
        }
    }

    public function marketValue():Decimal
    {
        return DecimalMath::multiply($this->quantity,$this->markPrice);
    }

    public function costBasis():Decimal
    {
        return DecimalMath::multiply($this->quantity,$this->averageEntryPrice);
    }

    public function unrealizedPnl():Decimal
    {
        $movement=DecimalMath::subtract($this->markPrice,$this->averageEntryPrice);
        if($this->side===PositionSide::Short)$movement=DecimalMath::negate($movement);
        return DecimalMath::multiply($this->quantity,$movement);
    }

    public function signedUnderlyingExposure():Decimal
    {
        $multiplier=$this->contractMultiplier??Decimal::fromString('1');
        $exposure=DecimalMath::multiply($this->quantity,$multiplier);
        return $this->side===PositionSide::Long?$exposure:DecimalMath::negate($exposure);
    }

    public function status():string
    {
        return $this->quantity->isZero()?'CLOSED':'OPEN';
    }
}
