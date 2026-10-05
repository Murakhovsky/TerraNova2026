<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum MarketPairPurpose:string
{
    case Reference='REFERENCE';
    case Arbitrage='ARBITRAGE';
    case Hedge='HEDGE';
    case RelativeValue='RELATIVE_VALUE';
    case Research='RESEARCH';
}
