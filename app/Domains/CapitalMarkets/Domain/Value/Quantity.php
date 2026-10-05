<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Quantity extends ValueObject
{
    public function __construct(
        public Decimal $amount,
        public InstrumentId $instrumentId,
    ) {
        if ($this->amount->isNegative()) {
            throw new InvalidArgumentException('Quantity cannot be negative.');
        }
    }
}
