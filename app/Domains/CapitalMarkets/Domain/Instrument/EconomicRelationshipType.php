<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

enum EconomicRelationshipType: string
{
    case UnderlyingOf = 'UNDERLYING_OF';
    case Represents = 'REPRESENTS';
    case References = 'REFERENCES';
    case DerivesValueFrom = 'DERIVES_VALUE_FROM';
    case SyntheticExposureTo = 'SYNTHETIC_EXPOSURE_TO';
    case Hedges = 'HEDGES';
    case RedeemableInto = 'REDEEMABLE_INTO';
    case CollateralizedBy = 'COLLATERALIZED_BY';
    case SettledIn = 'SETTLED_IN';
    case QuotedIn = 'QUOTED_IN';
    case CorrelatedWith = 'CORRELATED_WITH';
}
