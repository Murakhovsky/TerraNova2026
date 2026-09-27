<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum OptimizationTargetType:string
{
    case IcpProfile='icp_profile';
    case QualificationPolicy='qualification_policy';

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
