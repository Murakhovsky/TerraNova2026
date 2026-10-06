<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum ReferenceType:string
{
    case Nbbo='NBBO';
    case LastTrade='LAST_TRADE';
    case RegularClose='REGULAR_CLOSE';
    case ExtendedHours='EXTENDED_HOURS';
    case ProviderReference='PROVIDER_REFERENCE';
}
