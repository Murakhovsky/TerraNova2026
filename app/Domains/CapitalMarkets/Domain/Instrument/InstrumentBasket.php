<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class InstrumentBasket extends ValueObject
{
    /** @param list<InstrumentId> $instruments */
    public function __construct(public array $instruments)
    {
        if (count($this->instruments) < 2) {
            throw new InvalidArgumentException('Instrument basket requires at least two instruments.');
        }

        $ids = array_map(static fn (InstrumentId $id): string => $id->value(), $this->instruments);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Instrument basket cannot contain duplicate instruments.');
        }
    }
}
