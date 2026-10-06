<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum MarketRegime:string
{
    case HighVolatility='HIGH_VOLATILITY';
    case LowVolatility='LOW_VOLATILITY';
    case Trending='TRENDING';
    case Range='RANGE';
    case Crisis='CRISIS';
    case Normal='NORMAL';
    case HighFunding='HIGH_FUNDING';
    case LowLiquidity='LOW_LIQUIDITY';
}
