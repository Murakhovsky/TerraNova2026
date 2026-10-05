<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class InstrumentBasket extends ValueObject implements InstrumentBasketInterface
{
    /** @param list<InstrumentId> $members */
    public function __construct(private array $members)
    {
        if(count($this->members)<2)throw new InvalidArgumentException('Instrument basket requires at least two instruments.');
        $ids=array_map(static fn(InstrumentId $id):string=>$id->value(),$this->members);
        if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException('Instrument basket cannot contain duplicate instruments.');
    }

    public function instruments():array{return $this->members;}
}
