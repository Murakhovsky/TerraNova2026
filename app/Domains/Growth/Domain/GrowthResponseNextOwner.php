<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthResponseNextOwner:string
{
    case Growth='growth';
    case Sales='sales';
    case Service='service';
    case HumanReview='human_review';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
