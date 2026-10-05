<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;

final class MarketTime
{
    public static function epochMicroseconds(DateTimeImmutable $time):int
    {
        return ((int)$time->format('U'))*1_000_000+(int)$time->format('u');
    }

    public static function diffMilliseconds(DateTimeImmutable $later,DateTimeImmutable $earlier):int
    {
        return intdiv(self::epochMicroseconds($later)-self::epochMicroseconds($earlier),1_000);
    }
}
