<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Venue;

enum VenueInstrumentStatus:string
{
    case Active='ACTIVE';
    case Suspended='SUSPENDED';
    case Delisted='DELISTED';
}
