<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class FundingSettled extends ValueObject
{
    public function __construct(
        public string $positionReference,
        public string $venueId,
        public string $instrumentId,
        public Decimal $rate,
        public Decimal $notional,
        public Decimal $cashflow,
        public DateTimeImmutable $occurredAt,
    ){
        if($positionReference===''||$venueId===''||$instrumentId===''||$notional->isNegative()){
            throw new InvalidArgumentException('Invalid FundingSettled event.');
        }
    }
}
