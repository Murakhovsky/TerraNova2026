<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class PaperOrder extends ValueObject
{
    public function __construct(
        public string $id,
        public string $executionGroupId,
        public string $legId,
        public string $venueMarketId,
        public string $instrumentId,
        public ExecutionSide $side,
        public Decimal $requestedQuantity,
        public Decimal $filledQuantity,
        public PaperOrderState $state,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $submittedAt=null,
        public ?DateTimeImmutable $filledAt=null,
    ){
        if($id===''||$executionGroupId===''||$legId===''||!$requestedQuantity->isPositive()||$filledQuantity->isNegative()){
            throw new InvalidArgumentException('Invalid paper order.');
        }
        if($filledQuantity->compareTo($requestedQuantity)>0){
            throw new InvalidArgumentException('Filled quantity cannot exceed requested quantity.');
        }
    }

    public function remainingQuantity():Decimal
    {
        return \Domains\CapitalMarkets\Domain\Value\DecimalMath::subtract($this->requestedQuantity,$this->filledQuantity);
    }
}
