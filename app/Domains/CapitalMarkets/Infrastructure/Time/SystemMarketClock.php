<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Time;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;

final readonly class SystemMarketClock implements MarketClockInterface
{
    public function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
    public function reliable():bool{return true;}
}
