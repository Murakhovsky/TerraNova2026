<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;

interface MarketClockInterface
{
    public function now():DateTimeImmutable;
    public function reliable():bool;
}
