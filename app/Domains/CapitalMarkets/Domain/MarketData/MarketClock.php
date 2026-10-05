<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;

interface MarketClock
{
    public function now():DateTimeImmutable;
    public function isReliable():bool;
}
