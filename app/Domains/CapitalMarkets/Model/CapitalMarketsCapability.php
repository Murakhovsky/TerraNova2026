<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Model;

enum CapitalMarketsCapability: string
{
    case WorkspaceView = 'capital_markets.workspace.view';
    case InstrumentRead = 'capital_markets.instrument.read';
    case InstrumentManage = 'capital_markets.instrument.manage';
    case VenueRead = 'capital_markets.venue.read';
    case VenueManage = 'capital_markets.venue.manage';
    case ResearchRead = 'capital_markets.research.read';
    case ResearchManage = 'capital_markets.research.manage';
    case PaperExecute = 'capital_markets.paper.execute';
    case RiskView = 'capital_markets.risk.view';
    case RiskManage = 'capital_markets.risk.manage';
    case LiveExecute = 'capital_markets.live.execute';
    case AuditView = 'capital_markets.audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
