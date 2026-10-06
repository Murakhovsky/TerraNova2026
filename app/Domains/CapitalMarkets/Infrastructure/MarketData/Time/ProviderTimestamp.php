<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ProviderTimestamp
{
    private function __construct(){}

    public static function fromMilliseconds(string|int $value):DateTimeImmutable
    {
        $digits=self::digits($value);
        if(strlen($digits)<4)$digits=str_pad($digits,4,'0',STR_PAD_LEFT);
        $seconds=substr($digits,0,-3);
        $millis=substr($digits,-3);
        return self::fromSecondsAndMicros($seconds,$millis.'000');
    }

    public static function fromNanoseconds(string|int $value):DateTimeImmutable
    {
        $digits=self::digits($value);
        if(strlen($digits)<10)$digits=str_pad($digits,10,'0',STR_PAD_LEFT);
        $seconds=substr($digits,0,-9);
        $nanos=substr($digits,-9);
        return self::fromSecondsAndMicros($seconds,substr($nanos,0,6));
    }

    private static function digits(string|int $value):string
    {
        $digits=trim((string)$value);
        if(preg_match('/^[0-9]+$/',$digits)!==1)throw new InvalidArgumentException('Provider timestamp must be an unsigned integer.');
        return ltrim($digits,'0')?:'0';
    }

    private static function fromSecondsAndMicros(string $seconds,string $micros):DateTimeImmutable
    {
        $time=DateTimeImmutable::createFromFormat(
            '!U.u',
            (ltrim($seconds,'0')?:'0').'.'.str_pad($micros,6,'0',STR_PAD_RIGHT),
            new DateTimeZone('UTC'),
        );
        if($time===false)throw new InvalidArgumentException('Provider timestamp cannot be converted to UTC.');
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
