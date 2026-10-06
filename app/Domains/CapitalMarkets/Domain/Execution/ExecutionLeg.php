<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExecutionLeg extends ValueObject
{
    public function __construct(
        public string $id,
        public int $sequence,
        public string $venueMarketId,
        public string $instrumentId,
        public ExecutionSide $side,
        public Decimal $quantity,
        public string $orderType,
        public ?Decimal $limitPrice,
        public Decimal $targetPrice,
        public Decimal $expectedFee,
        public Decimal $expectedSlippage,
    ){
        if($id===''||$sequence<1||$venueMarketId===''||$instrumentId===''||!$quantity->isPositive()){
            throw new InvalidArgumentException('Invalid execution leg.');
        }
        if(!in_array($orderType,['MARKET','LIMIT','IOC','FOK'],true)){
            throw new InvalidArgumentException('Unsupported paper order type.');
        }
    }
}
