<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthResponseUrgency:string
{
    case Low='low';
    case Normal='normal';
    case High='high';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
