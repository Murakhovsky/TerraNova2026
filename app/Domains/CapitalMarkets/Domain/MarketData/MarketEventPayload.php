<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

interface MarketEventPayload
{
    /** @return array<string,mixed> */
    public function toArray():array;
}
