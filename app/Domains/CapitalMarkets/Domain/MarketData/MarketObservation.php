<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

interface MarketObservation
{
    public function eventType():MarketEventType;

    /** @return array<string,mixed> */
    public function toArray():array;
}
