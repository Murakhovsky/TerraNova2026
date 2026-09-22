<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum EngagementChannel:string
{
    case None='none';
    case Email='email';
    case Phone='phone';
    case LinkedIn='linkedin';
    case Manual='manual';
    case Internal='internal';

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
