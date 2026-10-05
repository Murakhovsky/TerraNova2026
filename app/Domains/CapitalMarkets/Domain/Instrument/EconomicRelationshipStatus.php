<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum EconomicRelationshipStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
