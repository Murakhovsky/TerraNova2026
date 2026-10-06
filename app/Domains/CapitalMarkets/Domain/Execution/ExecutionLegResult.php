<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExecutionLegResult extends ValueObject
{
    public function __construct(
        public string $legId,
        public Decimal $requestedQuantity,
        public Decimal $filledQuantity,
        public PaperOrderState $orderState,
        public ?string $failureReason=null,
    ){
        if($legId===''||!$requestedQuantity->isPositive()||$filledQuantity->isNegative()||$filledQuantity->compareTo($requestedQuantity)>0){
            throw new InvalidArgumentException('Invalid execution leg result.');
        }
    }

    public function remaining():Decimal
    {
        return DecimalMath::subtract($this->requestedQuantity,$this->filledQuantity);
    }

    public function fullyFilled():bool
    {
        return $this->filledQuantity->compareTo($this->requestedQuantity)===0;
    }
}
