<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

enum RateKind: string
{
    case Funding = 'FUNDING';
    case Yield = 'YIELD';
    case Interest = 'INTEREST';
    case Fee = 'FEE';
}
