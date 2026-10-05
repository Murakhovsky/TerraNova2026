<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

interface InstrumentBasketInterface
{
    /** @return list<InstrumentId> */
    public function instruments():array;
}
