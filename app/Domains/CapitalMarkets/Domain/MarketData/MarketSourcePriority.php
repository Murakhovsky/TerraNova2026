<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSourcePriority:string
{
    case Primary='PRIMARY';
    case Secondary='SECONDARY';
    case Fallback='FALLBACK';
}
