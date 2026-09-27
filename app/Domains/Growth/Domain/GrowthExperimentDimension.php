<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthExperimentDimension:string
{
    case Icp='icp';
    case Offer='offer';
    case Channel='channel';
    case Message='message';
    case Play='play';

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
