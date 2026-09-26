<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthOutcomeType:string
{
    case Contacted='contacted';
    case Qualified='qualified';
    case Disqualified='disqualified';
    case ReplyReceived='reply_received';
    case MeetingCompleted='meeting_completed';
    case Won='won';
    case Lost='lost';

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
