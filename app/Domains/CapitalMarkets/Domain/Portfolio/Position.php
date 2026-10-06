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
    ){
        if($instrumentId===''||$venueId===''||$quantity->isNegative()){
            throw new InvalidArgumentException('Invalid position identity or quantity.');
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
        return DecimalMath::multiply(
            $this->quantity,
            DecimalMath::subtract($this->markPrice,$this->averageEntryPrice)
        );
    }

    public function status():string
    {
        return $this->quantity->isZero()?'CLOSED':'OPEN';
    }
}
