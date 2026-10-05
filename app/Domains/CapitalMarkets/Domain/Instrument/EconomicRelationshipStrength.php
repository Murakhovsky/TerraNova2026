<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum EconomicRelationshipStrength: string
{
    case Exact = 'EXACT';
    case Direct = 'DIRECT';
    case Derived = 'DERIVED';
    case Statistical = 'STATISTICAL';
}
