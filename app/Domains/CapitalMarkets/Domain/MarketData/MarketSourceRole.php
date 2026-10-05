<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

enum MarketSourceRole:string
{
    case Trading='TRADING_SOURCE';
    case Reference='REFERENCE_SOURCE';
    case Validation='VALIDATION_SOURCE';
    case Historical='HISTORICAL_SOURCE';
    case Fx='FX_SOURCE';
}
