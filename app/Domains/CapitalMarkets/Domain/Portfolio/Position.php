<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Kernel\Shared\Domain\ValueObject;
final readonly class Position extends ValueObject
{
    public function __construct(
        public string $instrumentId, public string $venueId, public Decimal $quantity,
        public Decimal $averageEntryPrice, public Decimal $markPrice, public Decimal $fees,
        public Decimal $realizedPnl,
    ){}
    public function marketValue():Decimal{return DecimalMath::multiply($this->quantity,$this->markPrice);}
    public function unrealizedPnl():Decimal{return DecimalMath::multiply($this->quantity,DecimalMath::subtract($this->markPrice,$this->averageEntryPrice));}
}
