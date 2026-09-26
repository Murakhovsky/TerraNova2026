<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthResponseSentiment:string
{
    case Positive='positive';
    case Neutral='neutral';
    case Negative='negative';
    case Mixed='mixed';
    case Unclear='unclear';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
