<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum ExperimentDecisionType:string
{
    case PromoteVariant='promote_variant';
    case Iterate='iterate';
    case Continue='continue';
    case Stop='stop';
    case Inconclusive='inconclusive';

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
