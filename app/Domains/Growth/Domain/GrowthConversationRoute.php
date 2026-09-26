<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthConversationRoute:string
{
    case Growth='growth';
    case Sales='sales';
    case Service='service';
    case Partnership='partnership';
    case Suppression='suppression';
    case HumanReview='human_review';
    case NoAction='no_action';

    public function isCrossDomain():bool
    {
        return in_array($this,[self::Sales,self::Service],true);
    }

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
