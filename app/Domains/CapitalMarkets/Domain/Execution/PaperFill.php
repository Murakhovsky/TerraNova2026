<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Execution;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Kernel\Shared\Domain\ValueObject;
final readonly class PaperFill extends ValueObject
{
    public function __construct(
        public string $id, public string $executionGroupId, public string $venueId, public string $instrumentId,
        public ExecutionSide $side, public Decimal $quantity, public Decimal $price, public Decimal $fee,
        public Decimal $slippage, public DateTimeImmutable $filledAt, public string $idempotencyKey,
    ){}
    public function notional():Decimal{return DecimalMath::multiply($this->quantity,$this->price);}
}
