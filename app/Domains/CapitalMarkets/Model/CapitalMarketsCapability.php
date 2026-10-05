<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Model;

enum CapitalMarketsCapability:string
{
    case View='capital_markets.view';
    case Manage='capital_markets.manage';
    case InstrumentView='capital_markets.instrument.view';
    case InstrumentManage='capital_markets.instrument.manage';
    case RelationshipView='capital_markets.relationship.view';
    case RelationshipManage='capital_markets.relationship.manage';
    case VenueView='capital_markets.venue.view';
    case VenueManage='capital_markets.venue.manage';
    case AuditView='capital_markets.audit.view';

    /** @return list<string> */
    public static function values():array{return array_map(static fn(self $case):string=>$case->value,self::cases());}
}
