<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum EngagementDeliveryStatus:string
{
    case Accepted='accepted';
    case Sent='sent';
    case Delivered='delivered';
    case Failed='failed';
    case Started='started';
    case Completed='completed';
    case NoAnswer='no_answer';
    case Busy='busy';

    public function supportsChannel(EngagementChannel $channel):bool
    {
        return match($channel){
            EngagementChannel::LinkedIn=>in_array($this,[self::Accepted,self::Sent,self::Delivered,self::Failed],true),
            EngagementChannel::Phone=>in_array($this,[self::Accepted,self::Started,self::Completed,self::NoAnswer,self::Busy,self::Failed],true),
            default=>false,
        };
    }

    public function isTerminal():bool
    {
        return in_array($this,[self::Delivered,self::Failed,self::Completed,self::NoAnswer,self::Busy],true);
    }

    /** @return list<string> */
    public static function values():array
    {
        return array_map(static fn(self $case):string=>$case->value,self::cases());
    }
}
